<?php

namespace App\Support\Finance;

use App\Enums\Role;
use App\Mail\InvoicePaidMail;
use App\Mail\PaymentProofSubmittedMail;
use App\Models\Invoice;
use App\Models\User;
use App\Services\MailService;
use App\Support\Notify;

/**
 * **إشعارات الدفع اليدويّ ونتيجته — موضعٌ واحد** (قرار المالك 2026-10-03).
 *
 * ثبت بالكود: رفعُ العميل إثبات تحويل كان يغيّر حالة الفاتورة فقط، فلا تعلم الإدارة به ما لم تفتح «المالية».
 * واعتمادُ دفعة فاتورةٍ غير الاستشارة (أتعاب قضيّة أو تنفيذ) لم يكن يُشعر العميل في حسابه — فاتورة الاستشارة
 * لها رسائلها (`HandleConsultPaid`) فلا تُكرَّر هنا.
 */
final class PaymentNotices
{
    /** العميل رفع إثبات تحويل ⇐ كلّ حسابٍ في الإدارة العليا: إشعارٌ في الجرس وبريد. */
    public static function proofSubmitted(Invoice $invoice): void
    {
        $admins = User::where('role', Role::Admin)->where('status', '!=', 'suspended')->get();
        $client = (string) User::whereKey($invoice->user_id)->value('name') ?: 'العميل';

        foreach ($admins as $admin) {
            Notify::send($admin->id, 'card', 't-blue', "رفع {$client} إثبات تحويل للفاتورة {$invoice->number} بمبلغ "
                .number_format((int) $invoice->amount).' ر.س — بانتظار مراجعتك في «المالية».');
        }

        if ($admins->isNotEmpty()) {
            app(MailService::class)->send($admins->all(), new PaymentProofSubmittedMail($invoice));
        }
    }

    /** اعتُمدت دفعة فاتورةٍ (تحصيلاً يدويّاً أو عبر البوّابة) ⇐ العميل: إشعارٌ في الجرس وبريد. */
    public static function settled(Invoice $invoice): void
    {
        $client = User::find($invoice->user_id);
        if ($client === null) {
            return;
        }

        Notify::send($client->id, 'card', 't-green', "تم اعتماد دفعتك للفاتورة {$invoice->number} بمبلغ "
            .number_format((int) $invoice->amount).' ر.س — سند القبض متاح في «فواتيري».');
        app(MailService::class)->send($client, new InvoicePaidMail($invoice));
    }
}
