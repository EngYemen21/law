<?php

namespace App\Domain\Journey\Transitions\Consult;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Transition;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **إلغاء تسعيرٍ خاطئ ⇐ «بانتظار التسعير»** (زرّ «إلغاء التسعير» في شاشة الاستشارات).
 *
 * يناديه `ConsultBooking::reprice`. كان يكتب الحالة مباشرةً ويلغي الفواتير بتحديثٍ جماعيّ
 * لا يراه `StateWriteGuard` ولا يُسجَّل. السلوك نفسه، داخل المحرّك:
 * - الفاتورة غير المدفوعة **تُلغى ولا تُحذف** — صدرت باسم العميل وأُشعر بها.
 * - تُصفَّر `priced_at` وحدها، فيرى المسعّر رقمه السابق ويصحّحه.
 *
 * `from()` و`guard()` هما حارسا المتحكّم نفسيهما (`Staff\ConsultController`): المسعَّرة غير
 * المسدَّدة وحدها. يبقى المتحكّم يفحصهما أوّلاً برسائله، وهنا الضمانة لأيّ منادٍ آخر.
 *
 * @extends Transition<Consult>
 */
final class RepriceConsult extends Transition
{
    public function name(): string
    {
        return 'consult.reprice';
    }

    public function from(): array
    {
        return [ConsultStatus::AwaitingPayment->value];
    }

    public function to(Model $entity, array $payload): string
    {
        return ConsultStatus::AwaitingPricing->value;
    }

    public function deny(Model $entity, ?User $actor): ?string
    {
        return $actor === null ? 'إلغاء التسعير فعلٌ بشريّ.' : null;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Consult $entity */
        return $entity->paid_at !== null
            ? 'سُدِّدت هذه الفاتورة — تصحيحُها بعد السداد استردادٌ ماليّ لا إعادة تسعير.'
            : null;
    }

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Consult $entity */
        // صفّاً صفّاً لا تحديثاً جماعيّاً: كلُّ فاتورةٍ تمرّ بحدث الحفظ فيراها الحارس
        Invoice::where('consult_id', $entity->id)
            ->where('paid', false)
            ->get()
            ->each(fn (Invoice $invoice) => $invoice->update(['status' => 'ملغاة', 'tone' => 'b-red']));

        $entity->logAudit($actor->name ?? 'النظام', 'إلغاء التسعير', (string) ($payload['before'] ?? ''), '(بانتظار تسعيرٍ جديد)');
        $entity->priced_at = null;
    }

    public function record(array $payload): array
    {
        return ['previous_total' => $payload['before'] ?? null];
    }
}
