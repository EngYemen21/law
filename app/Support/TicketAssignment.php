<?php

namespace App\Support;

use App\Domain\Journey\Transitions\Ticket\ReferOnAssignment;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * الباقي هنا هو المشترك: `suggest`/`pickLawyer` (سياسة الاختيار — اقتراحٌ يؤكّده إنسان)
     * و`write` (كتابة الإسناد) و`escalateIfNoLawyer` (لا محامي أصلاً ⇒ الإدارة العليا).
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

    /**
     * **حارس إعادة الإسناد — واحدٌ للإدارة («توزيع التذاكر») والموظّف («تحويل التذاكر»)** (قرار المالك 2026-09-30).
     *
     * كان تحويل الموظّف بلا حارس، فيُسنِد تذكرةً مغلقة أو محوّلة لقضيّة/تنفيذ (ثبت في المتصفّح: SB-2026-4316
     * المغلقة صار لها محامٍ)؛ وحارس الإدارة يقيس `isTerminal()` فيُسنِد «مكتملة». القاعدة الآن قاعدة
     * `TicketWritePolicy` نفسها: المجمّدة وكلّ الحالات النهائيّة لا يُعاد إسنادها.
     */
    public static function assertReassignable(Ticket $ticket): void
    {
        abort_if((bool) $ticket->is_frozen, 422, 'التذكرة مجمّدة لاعتماد مسارها النهائي — لا يُعاد إسنادها.');
        abort_unless($ticket->isOpen(), 422, 'التذكرة نهائيّة — لا يُعاد إسنادها.');
    }

    /**
     * المحامي الذي أُسندت إليه التذكرة يعلم بها — إلّا إن كان هو من أسندها. بعد ختم الحفظ: الإسناد
     * الجماعيّ في معاملةٍ لكلّ عنصر، ولا يصل إشعارٌ عن إسنادٍ أُلغي.
     */
    public static function notifyAssigned(Ticket $ticket, User $lawyer, User $actor): void
    {
        if ((int) $lawyer->id === (int) $actor->id) {
            return;
        }

        DB::afterCommit(fn () => Notify::send($lawyer->id, 'folder', 't-blue', "أُسندت إليك التذكرة {$ticket->number} — {$ticket->type}. تابعها من «التذاكر»."));
    }

    // منطق التصعيد (الإسناد للإدارة العليا + الإشعار + البريد) انتقل إلى
    // App\Jobs\EscalateUnassignedTicketJob ليجري في الطابور لا في طلب فتح التذكرة.

    /**
     * يختار المحامي المختص حتمياً: تخصّص مطابق ← أقلّ حملاً ← أقدم معرّفاً.
     *
     * $requireSpecialty: يعيد null إن لم يوجد محامٍ في قسم التذكرة، بدل السقوط على كل
     * المحامين. الافتراضي false يحفظ سلوك المنادين القائمين (AssignTicketJob ·
     * CaseConversion::resolveLawyer · ExecutionCreation) حيث أيّ محامٍ أفضل من لا شيء.
     *
     * السياسة نفسها في `suggest` — هذه واجهتها المختصرة لمن يريد المحامي وحده.
     */
    public static function pickLawyer(Ticket $ticket, bool $requireSpecialty = false): ?User
    {
        $suggestion = self::suggest($ticket);

        // الصرامة تنطبق **فقط** حين للتذكرة قسم معروف: «لا يوجد متخصّص» تفترض تخصّصاً معلوماً.
        // والقسم اختياري في نموذج العميل، ويضبطه TriageTicketOnOpenJob بعد الفتح.
        if ($requireSpecialty && $suggestion->departmentKnown && ! $suggestion->specialist) {
            return null; // قسم معروف ولا محامي فيه — القرار يُصعَّد لا يُفرض
        }

        return $suggestion->lawyer;
    }

    /**
     * **اقتراح النظام لمحامي التذكرة** — سلسلة المالك (2026-09-25): مختصٌّ بالقسم ← وإلّا الأقلّ
     * حملاً **موسوماً بأنّه غير مختصّ** ← وإلّا لا أحد (فالتصعيد للإدارة العليا).
     *
     * اقتراحٌ يعرضه الطاقم ويؤكّده (شاشتا «توزيع التذاكر» و«تحويل التذاكر» ومودال التحويل) —
     * لا يكتب شيئاً: الإسناد قرارٌ بشريّ (قرار المالك 2026-09-20).
     */
    public static function suggest(Ticket $ticket): LawyerSuggestion
    {
        [$lawyers, $openCounts] = self::pool();

        return self::choose($ticket, $lawyers, $openCounts);
    }

    /**
     * اقتراحات قائمةٍ من التذاكر — مسبح المحامين وأحمالهم يُحمَّلان **مرّةً** لا لكلّ تذكرة.
     *
     * @param  iterable<Ticket>  $tickets
     * @return array<int, LawyerSuggestion> مفهرسةٌ بمعرّف التذكرة
     */
    public static function suggestMany(iterable $tickets): array
    {
        [$lawyers, $openCounts] = self::pool();

        $out = [];
        foreach ($tickets as $ticket) {
            $out[$ticket->id] = self::choose($ticket, $lawyers, $openCounts);
        }

        return $out;
    }

    /**
     * هل في المكتب محامٍ نشطٌ واحدٌ على الأقلّ — بأيّ وضع توزيع؟
     *
     * غير مسبح الاقتراح عمداً: محامٍ في وضع «يدويّ» لا يُقترح آليّاً، لكنّ الطاقم يملك إسناده —
     * فوجوده يعني أنّ للتذكرة من يُسنَد إليه، فلا تُصعَّد للإدارة بسببه.
     */
    public static function anyActiveLawyer(): bool
    {
        return User::where('role', Role::Lawyer)->where('status', 'active')->exists();
    }

    /**
     * **الحلقة الأخيرة من السلسلة عند الفتح:** لا محامي نشطاً في المكتب أصلاً ⇒ الإدارة العليا
     * صاحبة الملفّ من لحظته (`EscalateUnassignedTicketJob` نفسه — لا مسار تصعيدٍ ثانٍ).
     *
     * لماذا لا تُنتظر مهلة الإعدادات (ساعتان): المهلة نافذةٌ ليختار الطاقم محامياً، ولا محامي
     * يُختار؛ فانتظارها يترك التذكرة بلا صاحبٍ ساعتين بلا فائدة. وحين يوجد محامٍ ولو واحد، لا
     * يُسنِد الفتحُ أحداً (قرار 2026-09-20) وتبقى المهلة كما هي.
     */
    public static function escalateIfNoLawyer(Ticket $ticket): bool
    {
        if (self::anyActiveLawyer()) {
            return false;
        }

        EscalateUnassignedTicketJob::dispatch($ticket->id);

        return true;
    }

    /**
     * مسبح الاقتراح: المحامون النشطون في وضع التوزيع التلقائي فقط (الـ manual يُسنَد يدوياً)،
     * مع عدد التذاكر المفتوحة لكلٍّ منهم (لموازنة الحمل).
     *
     * @return array{0: Collection<int, User>, 1: Collection<int|string, mixed>}
     */
    private static function pool(): array
    {
        $lawyers = User::where('role', Role::Lawyer)
            ->where('status', 'active')
            ->where('distribution_mode', 'auto')
            ->with('specialties')
            ->get();

        $openCounts = $lawyers->isEmpty() ? collect() : Ticket::whereIn('assigned_lawyer_id', $lawyers->pluck('id'))
            ->open()
            ->selectRaw('assigned_lawyer_id, count(*) as c')
            ->groupBy('assigned_lawyer_id')
            ->pluck('c', 'assigned_lawyer_id');

        return [$lawyers, $openCounts];
    }

    /**
     * @param  Collection<int, User>  $lawyers
     * @param  Collection<int|string, mixed>  $openCounts
     */
    private static function choose(Ticket $ticket, Collection $lawyers, Collection $openCounts): LawyerSuggestion
    {
        $dept = $ticket->department;
        if ($lawyers->isEmpty()) {
            return LawyerSuggestion::none($dept);
        }

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
        return new LawyerSuggestion($ordered->first(), $deptMatched->isNotEmpty(), $deptKnown, $dept);
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
