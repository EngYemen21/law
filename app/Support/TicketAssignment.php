<?php

namespace App\Support;

use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LegalAiService;

/**
 * الإسناد الأول للتذكرة — **حتميّ بالكامل، بلا أي نداء ذكاء اصطناعي**.
 * منطق حتمي بالكامل يعمل ولو كان الذكاء الاصطناعي معطّلاً (اختبارات/غياب مزوّد): يفضّل مطابقة التخصّص
 * ثم الأقل حملاً ثم الأقدم. مصدر موحّد يُستدعى عند فتح التذكرة وعند الإحالة إن لزم.
 */
class TicketAssignment
{
    /** يُسنِد المحامي المختص. يُعيد المحامي المسند أو null إن لا محامٍ. */
    public static function assign(Ticket $ticket): ?User
    {
        // الإسناد الأوّل يشترط التخصّص: بلا متخصّص تبقى التذكرة بلا محامٍ ويُصعَّد
        // الأمر للإدارة (قرار صاحب المنتج: الإسناد ليس ملزَماً فوراً، ويمكن أن يقع
        // أثناء المحادثة من شاشة التوزيع أو الإحالة).
        $lawyer = self::pickLawyer($ticket, requireSpecialty: true);
        if (! $lawyer) {
            // مطابورة: الإشعار والبريد لا يجوز أن يُبطئا فتح التذكرة ولا أن يُسقطاه
            EscalateUnassignedTicketJob::dispatch($ticket->id);

            return null;
        }

        $updates = [
            'assigned_lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
        ];

        // قفزة «محالة للقسم القانوني» للوضع البشري فقط (وكيل الاستقبال معطّل — كي لا تعلق التذكرة).
        // مع الوكيل المفعّل كانت تسبق الترحيب فيرتدّ المسار للخلف (محالة ← بانتظار مستندات)،
        // ويقرأ العميل «تمت الإحالة» قبل أن تُطلب مستنداته، ويُقفَل ردّ AI في فجوة الطابور.
        if (! TicketTriage::enabled() && in_array($ticket->status, ['جديدة', 'قيد التحليل'], true)) {
            $updates['status'] = 'محالة للقسم القانوني';
            $updates['tone'] = TicketJourney::toneFor('محالة للقسم القانوني');
            $updates['last_message'] = 'تمت إحالة طلبكم إلى القسم القانوني المختص لدراسة الموضوع.';
            $updates['date_label'] = 'الآن';
        }

        $ticket->update($updates);
        Live::push(new TicketStatusBroadcast($ticket));

        return $lawyer;
    }

    // منطق التصعيد (الإسناد للإدارة العليا + الإشعار + البريد) انتقل إلى
    // App\Jobs\EscalateUnassignedTicketJob ليجري في الطابور لا في طلب فتح التذكرة.

    /**
     * يختار المحامي المختص حتمياً: تخصّص مطابق ← أقلّ حملاً ← أقدم معرّفاً.
     *
     * $requireSpecialty: يعيد null إن لم يوجد محامٍ في قسم التذكرة، بدل السقوط على كل
     * المحامين. الافتراضي false يحفظ سلوك المنادين القائمين (AssignTicketJob ·
     * CaseConversion::resolveLawyer · DistributeController::auto) حيث أيّ محامٍ أفضل من لا شيء.
     * الإسناد الأوّل وحده يمرّر true: إسناد تذكرة عمالية لمحامي عقارات **صامتاً** أسوأ من
     * تركها للإدارة تُسنِدها بوعي (escalateUnassigned).
     */
    public static function pickLawyer(Ticket $ticket, bool $requireSpecialty = false): ?User
    {
        // المحامون النشطون في وضع التوزيع التلقائي فقط (الـ manual يُسنَد يدوياً من Distribute)
        $lawyers = User::where('role', Role::Lawyer)
            ->where('status', 'active')
            ->where('distribution_mode', 'auto')
            ->get();
        if ($lawyers->isEmpty()) {
            return null;
        }

        // عدد التذاكر المفتوحة لكل محامٍ (لموازنة الحمل)
        $openCounts = Ticket::whereIn('assigned_lawyer_id', $lawyers->pluck('id'))
            ->whereNotIn('status', ['مكتملة', 'مغلقة'])
            ->selectRaw('assigned_lawyer_id, count(*) as c')
            ->groupBy('assigned_lawyer_id')
            ->pluck('c', 'assigned_lawyer_id');

        // اقصر المرشحين على المطابقين للتخصّص إن وُجدوا، وإلا الكل
        $dept = $ticket->department;
        /*
         * **«كل الأقسام» تُطابق كلَّ قسم.**
         *
         * كانت المقارنة نصّيّةً حرفيّة (`->where('department', $dept)`)، فمحامٍ قسمُه
         * `Specialties::ALL_DEPARTMENTS` — وهي قيمةٌ شاملةٌ مقصودة — **لا يطابق أيّ
         * قسم**. وأثرُه أنّ مكتباً محاموه عامّون لا يُسنِد تذكرةً واحدة تلقائيّاً:
         * كلُّها تُصعَّد إلى الإدارة. قِيس على `SB-2026-1530`.
         *
         * و`Specialties::matches` موجودةٌ لهذا الغرض بالضبط وتُكرّم الشمول، ويستعملها
         * `LawyerAvailability` و`Staff\ConsultController` — وكان هذا المسار وحده يتجاهلها.
         */
        $deptMatched = $dept
            ? $lawyers->filter(fn (User $u) => Specialties::matches($u->department, $dept))->values()
            : collect();
        // الصرامة تنطبق **فقط** حين للتذكرة قسم معروف: «لا يوجد متخصّص» تفترض تخصّصاً
        // معلوماً. والقسم اختياري في نموذج العميل، ويضبطه TriageTicketOnOpenJob **بعد**
        // الإسناد لا قبله — فتصعيد كل تذكرة بلا قسم يُغرق الإدارة بلا فائدة.
        if ($requireSpecialty && $dept && $deptMatched->isEmpty()) {
            return null; // قسم معروف ولا محامي فيه — القرار يُصعَّد لا يُفرض
        }

        $pool = $deptMatched->isNotEmpty() ? $deptMatched : $lawyers;

        // ترتيب حتمي: الأقل حملاً ثم الأقدم معرّفاً
        $ordered = $pool->sortBy(fn ($u) => sprintf('%09d-%09d', (int) ($openCounts[$u->id] ?? 0), $u->id))->values();

        // ⚠️ نداء chooseLawyer أُحيل للتقاعد. ثلاثة أسباب:
        // (1) الكلفة: run() أوّل ما يفعل WebTimeLimit::raise(150) — فطلب فتح التذكرة
        //     قد يبقى معلّقاً دقيقتين ونصفاً والعميل بلا تذكرة.
        // (2) القيمة صفر: كان يُسأل «اختر الأنسب تخصّصاً» من مسبح مُرشَّح بالتخصّص سلفاً.
        // (3) السابقة: rankLawyers أسفل نفس الملفّ في LegalAiService أُحيلت للتقاعد
        //     لنفس السبب حرفياً («كانت تعلّق الطلب حتى 150ث»).
        // محفوظ للرجوع:
        //   $id = app(LegalAiService::class)->chooseLawyer($ticket, $ordered);
        //   return $ordered->firstWhere('id', $id) ?? $ordered->first();
        return $ordered->first();
    }

    /**
     * انتشار تغيّر محامي التذكرة إلى استشاراتها المفتوحة (غير المنتهية): تحديث المحامي المسند
     * حتى لا تبقى الاستشارة معزولة عند المحامي القديم بعد التحويل/إعادة الإسناد.
     * القضايا/التنفيذ تحتفظ بمحاميها بحسب التصميم.
     */
    public static function syncRelatedConsults(Ticket $ticket): void
    {
        if (! $ticket->assigned_lawyer_id) {
            return;
        }

        Consult::where('ticket_id', $ticket->id)
            ->where('session', '!=', 'منتهية')
            ->update([
                'assigned_lawyer_id' => $ticket->assigned_lawyer_id,
                'lawyer' => $ticket->assigned_lawyer,
            ]);
    }
}
