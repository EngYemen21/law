<?php

namespace App\Support\Finance;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * **سند القبض** — يُمنح للدفعة الناجحة مرّةً واحدة: رقمٌ متسلسل وتاريخ القبض وطريقته ومن قبضه.
 *
 * مصدرٌ واحد لكلّ مداخل القبض: دفعة ميسّر (`PaymentReconciler::record`) والتحصيل اليدويّ
 * (`PaymentReconciler::settleManual` بعد نجاح التسوية — فلا يُستهلك رقمٌ لتحصيلٍ رُفض).
 * ومتكرّر الاستدعاء بلا أثر: الإشعار والعودة من البوّابة قد يصلان معاً للدفعة نفسها.
 */
final class ReceiptVoucher
{
    public const PREFIX = 'RV';

    private const MAX_TRIES = 5;

    public static function issue(Payment $payment, ?User $by = null, ?string $method = null, ?string $note = null): Payment
    {
        if (! $payment->isReceived() || $payment->receipt_no !== null) {
            return $payment;
        }

        $receivedAt = $payment->received_at ?? $payment->reconciled_at ?? now();
        $payment->fill([
            'received_at' => $receivedAt,
            'method' => $method ?? ($payment->gateway === 'manual' ? null : 'gateway'),
            'actor_id' => $by?->id,
            'note' => $note,
        ]);

        for ($try = 1; ; $try++) {
            $payment->receipt_no = VoucherNumber::next(self::PREFIX, [[Payment::class, 'receipt_no']], $receivedAt);

            try {
                $payment->save();

                return $payment;
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= self::MAX_TRIES) {
                    throw $e;
                }
            }
        }
    }
}
