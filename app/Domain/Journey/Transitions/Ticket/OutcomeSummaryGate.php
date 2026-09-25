<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Models\JourneyTransition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Audit;

/**
 * **لا قرارَ مآلٍ بلا ملخّصٍ معتمد** (قرار المالك 2026-09-25 — ث٥).
 *
 * قِيس حيّاً: التذكرة SB-2026-1018 مضت من «مفتوحة» إلى «بانتظار مستندات» إلى مقترح مسارٍ
 * اعتُمد، فوُلد ملفّ التنفيذ EXE-2026-5098 — **بلا ملخّص، وبلا محامٍ مسنَد، وبلا رأيٍ قانونيّ**.
 * السبب أنّ `ProposeOutcomeTrack` و`ApproveOutcomeTrack` يقبلان كلّ حالةٍ مفتوحة (ومنها «جديدة»
 * و«قيد التحليل» و«بانتظار مستندات») ولا يطلبان شيئاً من الملفّ سوى تسبيب المسار.
 *
 * **«معتمد» = الاعتماد النهائيّ من الإدارة العليا** (`TicketSummary::isApproved`، المرحلة الثانية)،
 * لا اعتماد المحامي وحده (`isLawyerApproved`): المرحلة الأولى لا يصل العميلَ منها شيء (قرار
 * 2026-09-14)، والقرار المصيريّ يُنشر للعميل — فلا يقوم على ما لم يُعتمد للنشر. والملخّص المعتمد
 * نهائيّاً يعني ضمناً أنّ محامياً أُسند إليه واعتمده، فيُغلق الشرط ثغرتَي «بلا محامٍ» و«بلا رأي».
 *
 * **المسار السريع للإدارة العليا وحدها:** لها أن تمضي بلا ملخّص إن دوّنت سبب التجاوز — داخل
 * الانتقالين نفسيهما والبطاقة نفسها (ADR-009: لا مسار التفاف ولا نقطة نهاية جديدة). والسبب
 * يُحفظ في سجلّ الانتقال (`journey_transitions.payload`) وفي سجلّ التدقيق.
 *
 * لماذا صنفٌ مستقلّ: الشرط يقرؤه انتقالان (الاقتراح والاعتماد) وبطاقة الواجهة (سبب تعطيل الزرّ)؛
 * يُعرَّف هنا مرّةً ويُقرأ منها كلّها، فلا تقول الواجهة «مسموح» ويرفض الخادم أو العكس.
 */
final class OutcomeSummaryGate
{
    /** مفتاح سبب التجاوز في حمولة الانتقال. */
    public const WAIVER = 'summary_waiver_reason';

    /** أقصر سببٍ مقبول — المقياس نفسه لتسبيب المسار. */
    public const WAIVER_MIN = 10;

    public static function summaryApproved(Ticket $ticket): bool
    {
        return (bool) $ticket->summary?->isApproved();
    }

    /**
     * لماذا لا يُرفع مقترحٌ ولا يُعتمد مسارٌ الآن؟ `null` = لا مانع.
     *
     * الرسالة تقول **ما الناقص تحديداً** لا «غير مسموح» عامّة: الموظّف يعرف منها أين تقف التذكرة
     * وممّن ينتظر. وهي التي تعرضها البطاقة سبباً لتعطيل الزرّ.
     */
    public static function blocker(Ticket $ticket): ?string
    {
        $summary = $ticket->summary;
        if ($summary?->isApproved()) {
            return null;
        }

        $missing = match (true) {
            $summary === null => 'ولا ملخّص لهذه التذكرة بعد (يُولَّد عند إحالتها إلى المحامي).',
            ! $summary->isLawyerApproved() => 'والملخّص بانتظار اعتماد المحامي.',
            default => 'والملخّص بانتظار الاعتماد النهائيّ من الإدارة العليا.',
        };

        return 'يلزم ملخّصٌ معتمد قبل رفع مقترح المآل أو اعتماده — '.$missing;
    }

    /** سبب التجاوز كما دُوِّن في الحمولة (مقصوصاً)، أو `null` إن لم يُدوَّن. */
    /**
     * **سببُ التجاوز الذي دوّنه المعتمِدُ نفسُه حين رفع المقترح** — فلا يُطلب منه ثانيةً (قرار المالك
     * 2026-09-25). كان المدير الذي رفع مقترحاً بلا ملخّصٍ معتمد وكتب سببه يُطالَب بكتابته مرّةً
     * أخرى ليعتمد مقترحه هو.
     *
     * ويُورَث من **سجلّ الرحلة** لا من ذاكرة الواجهة: آخرُ سطر اقتراحٍ لهذه التذكرة، ومن هذا المعتمِد
     * نفسه، وما زال مقترحُه هو القائم (`proposed_by_id`). فإن رفعه غيرُه طُلب السبب من المعتمِد —
     * التجاوز مسؤوليّةُ من يقرّره.
     */
    public static function inheritedWaiver(Ticket $ticket, ?User $approver): ?string
    {
        if ($approver === null || ! $approver->isAdmin() || $ticket->proposed_by_id !== $approver->id) {
            return null;
        }

        $row = JourneyTransition::where('entity_type', 'Ticket')
            ->where('entity_id', $ticket->id)
            ->where('transition', (new ProposeOutcomeTrack)->name())
            ->latest('id')
            ->first(['actor_id', 'payload']);

        if ($row === null || $row->actor_id !== $approver->id) {
            return null;
        }

        return self::waiver((array) $row->payload);
    }

    public static function waiver(array $payload): ?string
    {
        $reason = trim((string) ($payload[self::WAIVER] ?? ''));

        return $reason === '' ? null : $reason;
    }

    /**
     * **التجاوز امتيازٌ للإدارة العليا** (⇒ 403) — لمن يطلبه في الحمولة وليس إداريّاً.
     * و`null` للفاعل: قرار النظام لا يطلب تجاوزاً أصلاً، وإن طلبه فلا امتياز له.
     */
    public static function denyWaiver(?User $actor, array $payload): ?string
    {
        if (self::waiver($payload) === null || $actor?->isAdmin()) {
            return null;
        }

        return 'تجاوز شرط الملخّص المعتمد امتيازٌ للإدارة العليا وحدها — ارفع المقترح بعد اعتماد الملخّص.';
    }

    /** حارس الملفّ (⇒ 422): ملخّصٌ معتمد، أو سبب تجاوزٍ كافٍ (وصاحبه إداريّ — حرسه `denyWaiver`). */
    public static function guard(Ticket $ticket, array $payload): ?string
    {
        if (($why = self::blocker($ticket)) === null) {
            return null;
        }

        $waiver = self::waiver($payload);
        if ($waiver === null) {
            return $why;
        }
        if (mb_strlen($waiver) < self::WAIVER_MIN) {
            return 'سبب المضيّ بلا ملخّصٍ معتمد قصير — دوِّنه بوضوح ('.self::WAIVER_MIN.' أحرف على الأقل).';
        }

        return null;
    }

    /**
     * هل مضى هذا الانتقال **بالتجاوز فعلاً**؟ يُعيد السبب إن كان الملخّص غير معتمد والسبب مدوَّناً.
     * سببٌ أُرسل وملخّصٌ معتمد لا يُسجَّل تجاوزاً: لم يُتجاوز شيء.
     */
    public static function usedWaiver(Ticket $ticket, array $payload): ?string
    {
        return self::summaryApproved($ticket) ? null : self::waiver($payload);
    }

    /** ما يُحفظ في `journey_transitions.payload` عند التجاوز. */
    public static function record(?string $waiver): array
    {
        return $waiver === null ? [] : ['summary_waived' => true, self::WAIVER => $waiver];
    }

    /** قيدٌ في سجلّ التدقيق بتحذير — تجاوزُ شرطٍ حاكم يُرى في المراجعة لا يُدفن في السجلّ العامّ. */
    public static function audit(Ticket $ticket, ?User $actor, string $step, string $waiver): void
    {
        $actorName = $actor?->name ?? 'الإدارة العليا';

        Audit::log(
            action: 'تجاوز شرط الملخّص المعتمد',
            description: "مضت {$actorName} في {$step} للتذكرة {$ticket->number} بلا ملخّصٍ معتمد. السبب: {$waiver}",
            category: 'تذاكر',
            severity: 'warning',
            auditable: $ticket,
            auditableRef: $ticket->number,
            afterState: [self::WAIVER => $waiver],
            user: $actor,
        );
    }
}
