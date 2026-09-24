<?php

namespace App\Support;

use App\Domain\Journey\Transitions\Ticket\ReferOnAssignment;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;

/**
 * إسناد التذكرة إلى محامٍ — **حتميّ بالكامل، بلا أيّ نداء ذكاء اصطناعيّ**.
 *
 * الإسناد قرارٌ بشريّ (قرار المالك 2026-09-20): الموظّف من «تحويل التذاكر»، والإدارة من «توزيع
 * التذاكر»، ومنها زرّ التوزيع الجماعيّ الذي يستعمل سياسة الاختيار أدناه. وما بقي بلا محامٍ بعد
 * مهلة الإعدادات تُسنده مهمّة التصعيد للإدارة العليا.
 *
 * السياسة حتميّة وتعمل ولو كان الذكاء الاصطناعيّ معطّلاً: تخصّصٌ مطابق ثمّ الأقلّ حملاً ثمّ الأقدم.
 */
class TicketAssignment
{
    /*
     * **حُذف الإسناد الأوّل التلقائيّ** (قرار المالك 2026-09-20): كانت `assign()` تُنادى لحظة فتح
     * التذكرة فتختار المحامي المختصّ، أو تُصعّد للإدارة إن لم يوجد. صار الإسناد قرارًا بشريًّا:
     * الموظّف من «تحويل التذاكر»، والإدارة من «توزيع التذاكر» (ومنها زرّ التوزيع الجماعيّ عبر
     * `AssignTicketJob`). وما بقي بلا محامٍ بعد مهلة الإعدادات تُسنده مهمّة التصعيد للإدارة العليا.
     *
     * الباقي هنا هو المشترك: `pickLawyer` (سياسة الاختيار) و`write` (كتابة الإسناد).
     */

    /**
     * **كتابة الإسناد — مصدرٌ واحد للمنادين الأربعة** (الإسناد الأوّل · التوزيع الآليّ · التصعيد ·
     * الإسناد اليدويّ). كان كلٌّ منهم ينسخ الشرط والحقول نفسها ويكتب الحالة مباشرةً.
     *
     * قفزة «محالة للقسم القانوني» للوضع البشري فقط (وكيل الاستقبال معطّل — كي لا تعلق التذكرة)،
     * وتمرّ بالمحرّك (`ReferOnAssignment`). مع الوكيل المفعّل كانت تسبق الترحيب فيرتدّ المسار
     * للخلف (محالة ← بانتظار مستندات)، ويقرأ العميل «تمت الإحالة» قبل أن تُطلب مستنداته،
     * ويُقفَل ردّ AI في فجوة الطابور. وفي غير الحالتين يُكتب الإسناد وحده كما كان.
     *
     * $lawyerName: الاسم المعروض — للتصعيد «الإدارة العليا» لا اسم الإداريّ.
     * $actor: من أسند يدوياً؛ `null` لقرار النظام.
     */
    public static function write(Ticket $ticket, int $lawyerId, string $lawyerName, ?User $actor = null): void
    {
        $refer = new ReferOnAssignment;

        if (! TicketTriage::enabled() && $refer->accepts((string) $ticket->status)) {
            Workflow::run($refer, $ticket, $actor, ['lawyer_id' => $lawyerId, 'lawyer_name' => $lawyerName]);

            return;
        }

        $ticket->update([
            'assigned_lawyer' => $lawyerName,
            'assigned_lawyer_id' => $lawyerId,
        ]);
    }

    // منطق التصعيد (الإسناد للإدارة العليا + الإشعار + البريد) انتقل إلى
    // App\Jobs\EscalateUnassignedTicketJob ليجري في الطابور لا في طلب فتح التذكرة.

    /**
     * يختار المحامي المختص حتمياً: تخصّص مطابق ← أقلّ حملاً ← أقدم معرّفاً.
     *
     * $requireSpecialty: يعيد null إن لم يوجد محامٍ في قسم التذكرة، بدل السقوط على كل
     * المحامين. الافتراضي false يحفظ سلوك المنادين القائمين (AssignTicketJob ·
     * CaseConversion::resolveLawyer · DistributeController::auto) حيث أيّ محامٍ أفضل من لا شيء.
     * ولا يمرّره منادٍ اليوم بعد حذف الإسناد الأوّل التلقائيّ؛ يبقى لأنّ سياسة «لا إسناد صامتاً
     * خارج التخصّص» قد تُطلب في توزيعٍ جماعيّ صارم.
     */
    public static function pickLawyer(Ticket $ticket, bool $requireSpecialty = false): ?User
    {
        // المحامون النشطون في وضع التوزيع التلقائي فقط (الـ manual يُسنَد يدوياً من Distribute)
        $lawyers = User::where('role', Role::Lawyer)
            ->where('status', 'active')
            ->where('distribution_mode', 'auto')
            ->with('specialties')
            ->get();
        if ($lawyers->isEmpty()) {
            return null;
        }

        // عدد التذاكر المفتوحة لكل محامٍ (لموازنة الحمل)
        $openCounts = Ticket::whereIn('assigned_lawyer_id', $lawyers->pluck('id'))
            ->open()
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
        // المطابقة بمعرّف القسم في الكتالوج وتخصّصات المحامي المتعدّدة (LawyerSpecialties) —
        // والنصّ القديم احتياطٌ لتذكرةٍ بلا معرّف
        $deptId = $ticket->legal_department_id;
        $deptKnown = $deptId !== null || filled($dept);
        $deptMatched = $deptKnown
            ? $lawyers->filter(fn (User $u) => LawyerSpecialties::covers($u, $deptId, $dept))->values()
            : collect();
        // الصرامة تنطبق **فقط** حين للتذكرة قسم معروف: «لا يوجد متخصّص» تفترض تخصّصاً
        // معلوماً. والقسم اختياري في نموذج العميل، ويضبطه TriageTicketOnOpenJob **بعد**
        // الإسناد لا قبله — فتصعيد كل تذكرة بلا قسم يُغرق الإدارة بلا فائدة.
        if ($requireSpecialty && $deptKnown && $deptMatched->isEmpty()) {
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
