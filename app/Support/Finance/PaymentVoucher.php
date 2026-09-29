<?php

namespace App\Support\Finance;

use App\Models\Expense;
use App\Models\StaffPayout;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * **سند الصرف** — رقمٌ متسلسل من دفترٍ واحد (`PV-YYYY-NNNNN`) لكلّ ما خرج من المكتب:
 * المصروف عند اعتماده (`Expenses::approve`)، وقيد صرف مستحقّات الموظّف عند تسجيله
 * (`StaffPayoutController::store`). ومتكرّر الاستدعاء بلا أثر: ما حمل رقماً لا يُعطى ثانياً.
 */
final class PaymentVoucher
{
    public const PREFIX = 'PV';

    /** مصادر دفتر سندات الصرف — كلّها تُقرأ لحساب التالي. */
    public const SOURCES = [[Expense::class, 'voucher_no'], [StaffPayout::class, 'voucher_no']];

    private const MAX_TRIES = 5;

    /**
     * @template T of Expense|StaffPayout
     *
     * @param  T  $record
     * @return T
     */
    public static function issue(Expense|StaffPayout $record): Expense|StaffPayout
    {
        if ($record->voucher_no !== null) {
            return $record;
        }

        // سنة السند سنةُ الصرف نفسه، لا سنةُ تسجيله
        $at = $record instanceof Expense ? $record->spent_on : $record->paid_at;

        for ($try = 1; ; $try++) {
            $record->voucher_no = VoucherNumber::next(self::PREFIX, self::SOURCES, $at);

            try {
                $record->save();

                return $record;
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= self::MAX_TRIES) {
                    throw $e;
                }
            }
        }
    }
}
