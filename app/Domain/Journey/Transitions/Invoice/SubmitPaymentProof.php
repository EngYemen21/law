<?php

namespace App\Domain\Journey\Transitions\Invoice;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Transition;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * **العميل يرفع إثبات تحويلٍ يدويّ ⇐ «بانتظار مراجعة الإثبات».** نقيضه `RejectPaymentProof`.
 *
 * كان `InvoiceController::uploadProof` يكتب الحالة مباشرةً. تخزين الملفّ نفسه يبقى عند المتحكّم
 * (شبكةٌ وقرص — لا مكان لهما داخل المعاملة)؛ ويصل مساره في الحمولة: `proof_path`.
 *
 * **لا إثباتَ لمدفوعةٍ ولا لملغاة** (قرار المالك 2026-09-19). كان الرفع بلا شرط، فإثباتٌ على
 * فاتورةٍ ملغاة (فاتورة الـ500 بعد إعادة تسعيرها بـ300 مثلاً) يمحو علامة إلغائها — فتصير
 * «بانتظار مراجعة الإثبات»، ولا يعرف زرُّ «تحصيل» أنّها أُلغيت فيُحصّلها ويسدّد بها الاستشارة.
 * `from()` مفتوح لبقيّة الحالات كما كان، و`guard()` يردّ الحالتين.
 *
 * @extends Transition<Invoice>
 */
final class SubmitPaymentProof extends Transition
{
    public function name(): string
    {
        return 'invoice.submit_proof';
    }

    public function from(): array
    {
        return self::ANY;
    }

    public function to(Model $entity, array $payload): string
    {
        return InvoiceStatus::ProofReview->value;
    }

    public function guard(Model $entity, array $payload): ?string
    {
        /** @var Invoice $entity */
        return match (true) {
            $entity->paid => self::PAID,
            $entity->isCancelled() => self::CANCELLED,
            array_key_exists('proof_path', $payload) && ! is_string($payload['proof_path']) => 'ملفّ الإثبات غير صالح.',
            default => null,
        };
    }

    /** رسالتا الرفض — يعرضهما المتحكّم أيضاً قبل تخزين الملفّ، فلا يبقى على القرص ملفٌّ يتيم. */
    public const PAID = 'هذه الفاتورة مدفوعة — لا حاجة لإثبات تحويل.';

    public const CANCELLED = 'أُلغيت هذه الفاتورة ولا تُسدَّد — ادفع الفاتورة المحدَّثة.';

    public function apply(Model $entity, ?User $actor, array $payload): void
    {
        /** @var Invoice $entity */
        $entity->proof_path = (string) $payload['proof_path'];
        $entity->proof_uploaded_at = now();
        $entity->tone = 'b-blue';
    }
}
