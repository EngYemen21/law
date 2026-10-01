<?php

namespace App\Events\Journey;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/** فاتورةٌ خرجت من المطالبة بلا سداد — أُلغيت أو أُعدمت (`CancelInvoice`، `WriteOffInvoice`). */
final class InvoiceVoided
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
