<?php

namespace App\Domain\Journey\Enums;

/** حالة الفاتورة المخزّنة («متأخرة» تُشتقّ عند القراءة — `Invoice::liveStatus`). */
enum InvoiceStatus: string
{
    case Due = 'مستحقة';
    case ProofReview = 'بانتظار مراجعة الإثبات';
    case Paid = 'مدفوعة';
    case Cancelled = 'ملغاة';

    /** تقبل دفعةً؟ — الملغاة لا تُحيي شيئاً (ع١، ع٢). */
    public function isPayable(): bool
    {
        return $this === self::Due || $this === self::ProofReview;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
