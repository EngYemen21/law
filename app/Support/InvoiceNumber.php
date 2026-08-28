<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * رقم الفاتورة — غلاف رفيع فوق ReferenceNumber يحفظ اسماً معبّراً لأربعة مستدعين.
 * منطق إعادة المحاولة والمدى الموسّع موحّد هناك لكل كيانات المنظومة.
 */
class InvoiceNumber
{
    /** يعيد رقم فاتورة غير مستعمل لهذه السنة. */
    public static function next(): string
    {
        return ReferenceNumber::next(Invoice::class, 'number', 'INV');
    }
}
