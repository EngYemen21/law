<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Transition;
use App\Models\TicketSummary;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **المستشار يعتمد ملخّص الملفّ ويرفعه للإدارة** — عمود `ticket_summaries.status` ⇐ «بانتظار الإدارة»
 * (المرحلة الأولى من اعتمادين — لا يصل العميلَ شيء؛ قرار المالك 2026-09-14).
 *
 * يناديه `Lawyer\TicketController::approveSummary`. كان يكتب الحالة مباشرةً مع نصّ الملخّص
 * وختم الاعتماد في حفظٍ واحد — وهنا الحفظ نفسه داخل المحرّك، والنصّ يأتي في الحمولة لأنّ
 * المحرّك يعيد قراءة الصفّ مقفولاً فلا يرى تعديلاتٍ لم تُحفظ على نسخة المنادي.
 *
 * `from()` مفتوحة كما كان: المنادي لا يفحص قيمة العمود بل **ختمَي الاعتماد** (نُشر؟ اعتمده
 * المستشار؟)، وملخّصاتٌ قائمة تحمل قيماً قديمة. فالحارس يكرّر فحصَي المنادي برسالتيهما.
 *
 * الحمولة: `fields` (الحقول الأربعة كما أُرسلت) · `edited` (هل تغيّر النصّ ⇒ «حُرّر بيد المستشار»).
 *
 * @extends Transition<TicketSummary>
 */
final class LawyerApproveTicketSummary extends Transition
{
    public const FIELDS = TicketSummary::RICH_TEXT_FIELDS;

    public function name(): string
    {
        return 'ticket_summary.lawyer_approved';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return 'awaiting_admin';
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor === null ? 'اعتماد الملخّص فعلُ مستشارٍ معروف.' : null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var TicketSummary $entity */
        return match (true) {
            $entity->isApproved() => 'اعتُمد هذا الملخّص ونُشر للعميل — لا يُعاد اعتماده.',
            $entity->isLawyerApproved() => 'اعتمدتَ هذا الملخّص ورُفع للإدارة لاعتماده النهائيّ.',
            default => null,
        };
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var TicketSummary $entity */
        self::fillText($entity, $payload);
        $entity->lawyer_approved_at = now();
        $entity->lawyer_approved_by = $actor?->id;
        $entity->lawyer_id = $actor?->id;
    }

    public function record(array $payload): array
    {
        return ['edited' => (bool) ($payload['edited'] ?? false)];
    }

    /**
     * نصّ الملخّص كما أرسله المعتمِد، و«حُرّر بيد المستشار» إن تغيّر — فلا تكتب فوقه إعادة
     * التوليد الآليّة (ع٢٦). مشتركٌ مع الاعتماد النهائيّ (`FinalApproveTicketSummary`).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fillText(TicketSummary $summary, array $payload): void
    {
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        // النصّ ونسخته المنسّقة معاً (`TicketSummary::editableInput`)
        $allowed = [...self::FIELDS, ...array_map(fn (string $f) => $f.'_html', self::FIELDS)];
        $summary->fill(array_intersect_key($fields, array_flip($allowed)));

        if ($payload['edited'] ?? false) {
            $summary->edited_at = now();
        }
    }
}
