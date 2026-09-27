<?php

namespace App\Domain\Journey\Transitions\Ticket;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Journey\Transition;
use App\Events\Journey\TicketStatusCorrected;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Database\Eloquent\Model;

/**
 * **تصحيح حالة التذكرة — للإدارة العليا وحدها، وبسببٍ مكتوب.**
 *
 * كانت قائمة «تغيير الحالة» بيد الموظّف، و`canTransition` يقبل أيّ تبديلٍ داخل رقم المرحلة:
 * فيُكمل الموظّف التذكرة متخطّياً اعتماد الإدارة (ع٥)، وينقض قرار الإغلاق (ع٦)، ويكرّر
 * «انعقدت الجلسة» (ع١٢). الرحلة الآن تتقدّم بأفعالٍ صريحة، وهذا الباب الاستثنائيّ الوحيد:
 * إداريّ، مسبَّب، مسجَّل.
 *
 * الحمولة: `status` (من الكتالوج، غير قديمة) · `reason` (إلزاميّ).
 *
 * **ولا تصحيحَ بعيداً عن حالةٍ أنتجت ملفّاً ما دام الملفّ قائماً** (قرار المالك 2026-09-25، ث٦):
 * صُحّحت تذكرةٌ «محولة إلى تنفيذ» وملفّها EXE-2026-5098 حيٌّ إلى «قيد التحليل»، فقرأ العميل أنّ
 * طلبه ما زال يُدرس، وبقيت مجمَّدةً ومسارها المعتمد «تنفيذ» والملفّ يتقدّم — حالةٌ تكذّب الواقع.
 * التجميد منع اعتماداً ثانياً (فلا ملفّ مكرّر) لكنّه لا يمنع بلوغ حالةٍ تناقضه؛ فالحارس هنا.
 *
 * **والاتّجاه المعاكس كذبٌ مثله:** تصحيحُ تذكرةٍ إلى «محولة إلى قضية/تنفيذ» ولا ملفّ لها يقول للعميل
 * إنّ له ملفّاً لا وجود له. فالحالتان لا تُبلغان تصحيحاً إلّا وملفّهما قائم (`Ticket::fileBehind`) —
 * والتحويل الحقيقيّ طريقه بطاقة المآل (`ApproveOutcomeTrack`) لا هذا الباب.
 *
 * **والإغلاق كذلك** (Zero Bypass — `CLAUDE.md` ق٦): «مغلقة» مسارٌ من مسارات البطاقة الأربعة، يُكتب
 * معه `closure_reason_code` ويُجمَّد السجلّ. كان هذا الباب يُغلق التذكرة بلا سببٍ مرمَّز ولا تجميد؛
 * فلا يبلغها. أمّا الخروج من «مغلقة» (أُغلقت خطأً) فيبقى تصحيحاً مشروعاً.
 *
 * @extends Transition<Ticket>
 */
final class CorrectTicketStatus extends Transition
{
    public function name(): string
    {
        return 'ticket.correct-status';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return (string) $payload['status'];
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor !== null && $actor->isAdmin() ? null : 'تصحيح حالة التذكرة للإدارة العليا وحدها.';
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Ticket $entity */
        // قبل فحص الحمولة: الملفّ القائم يصدّ **كلّ** هدف، فجواب `allowed()` (بلا حمولة) صحيحٌ به
        // — لا تصحيحَ ممكناً أصلاً — لا «مسموحٌ» ثمّ 422 عند الإرسال.
        if (($blocked = self::blocker($entity)) !== null || ! isset($payload['status'])) {
            return $blocked;
        }

        $target = TicketStatus::tryFrom((string) $payload['status']);

        return match (true) {
            $target === null => 'الحالة المطلوبة ليست من مراحل الرحلة.',
            $target->value === $entity->status => 'التذكرة في هذه الحالة أصلاً.',
            $target->isConversion() && $entity->fileBehind($target) === null => "لا ملفّ للتذكرة يشهد لحالة «{$target->value}» — التحويل يمرّ ببطاقة المآل لا بالتصحيح.",
            $target === TicketStatus::Closed => 'الإغلاق مآلٌ يمرّ ببطاقة القرار (مسار الإغلاق بسببه المرمَّز) لا بالتصحيح.',
            blank($payload['reason'] ?? null) => 'اذكر سبب التصحيح — يُحفظ في سجلّ التذكرة.',
            default => null,
        };
    }

    /**
     * سبب امتناع التصحيح عن هذه التذكرة كلّها، أو `null` — عامٌّ كي تقرأه أيّ شاشة تصحيحٍ تُبنى لاحقاً
     * فلا تعرض نموذجاً يردّه الخادم 422 — تقرؤه بطاقة الإدارة عبر `form()`.
     */
    public static function blocker(Ticket $ticket): ?string
    {
        $file = $ticket->producedFile();
        if ($file === null) {
            return null;
        }

        $kind = $file instanceof Execution ? 'ملفّ تنفيذ' : 'ملفّ قضيّة';

        return "للتذكرة {$kind} قائم ({$file->number}) — لا تُصحَّح حالتها بعيداً عن «{$ticket->status}» ما دام الملفّ قائماً.";
    }

    /**
     * **نموذج التصحيح كما يقبله الحارس** — المانع العامّ (إن وُجد) والأهداف المقبولة فعلاً.
     *
     * كانت بطاقة «تصحيح الحالة» تعرض نموذجاً دائماً وقائمةً مكتوبةً بيدٍ في الواجهة: تُعرض لتذكرةٍ
     * لها ملفٌّ قائم فيردّها الخادم 422 بعد كتابة السبب، وتنقصها حالتا المآل («بانتظار قرار المآل»
     * و«بانتظار اعتماد الإدارة للمسار»). الآن الأهداف من الكتالوج نفسه الذي يفحصه `guard` — بلا
     * الحالة الحاليّة، وبلا حالة تحويلٍ لا ملفّ وراءها — فلا يُعرض خيارٌ يُرفض.
     *
     * @return array{blocker: ?string, targets: list<array{value: string, label: string}>}
     */
    public static function form(Ticket $ticket): array
    {
        $blocker = self::blocker($ticket);
        if ($blocker !== null) {
            return ['blocker' => $blocker, 'targets' => []];
        }

        $targets = array_values(array_filter(
            TicketStatus::cases(),
            fn (TicketStatus $s) => $s->value !== $ticket->status
                && $s !== TicketStatus::Closed
                && (! $s->isConversion() || $ticket->fileBehind($s) !== null),
        ));

        return [
            'blocker' => null,
            'targets' => array_map(fn (TicketStatus $s) => ['value' => $s->value, 'label' => $s->value], $targets),
        ];
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        $status = (string) $payload['status'];
        $entity->tone = TicketJourney::toneFor($status);
        $entity->last_message = 'صحّحت الإدارة حالة التذكرة.';
        $entity->date_label = 'الآن';
    }

    public function events(Model $entity, string $from, ?User $actor, array $payload): array
    {
        return [new TicketStatusCorrected($entity, $from, (string) $payload['status'], (string) $payload['reason'], $actor->name ?? 'الإدارة')];
    }

    public function record(array $payload): array
    {
        return [];
    }
}
