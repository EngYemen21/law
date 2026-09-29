<?php

namespace App\Support\Finance;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\ReportPrint;

/**
 * **سند القبض مطبوعاً** — بتصميم مستندات المكتب نفسه (`ReportPrint`) وبيانات المكتب نفسها التي
 * تحملها الفاتورة (`TaxInvoiceDocument::officeCells`)، والمبلغ رقماً وكتابةً (`ArabicAmount`).
 */
final class ReceiptVoucherDocument
{
    public static function html(Payment $payment): string
    {
        // قراءةٌ مُنمَّطة: الدفعة قد تصل بلا فاتورةٍ مطابقة (`invoice_id` فارغ)، والعميل من الفاتورة
        $invoice = $payment->invoice_id !== null ? Invoice::find($payment->invoice_id) : null;
        $client = $invoice !== null ? User::find($invoice->user_id) : null;
        $halalas = (int) $payment->amount_halalas;
        // يُمنح مع الرقم (`ReceiptVoucher::issue`) — فالسند المطبوع يحمله دائماً
        $receivedAt = $payment->received_at;

        return ReportPrint::html([
            'title' => 'سند قبض',
            'subtitle' => $payment->methodLabel(),
            'ref' => (string) $payment->receipt_no,
            'blocks' => [
                ['title' => '١. بيانات المكتب', 'cellRows' => [TaxInvoiceDocument::officeCells($receivedAt)]],
                [
                    'title' => '٢. استلمنا من',
                    'cellRows' => [array_values(array_filter([
                        ['الاسم', (string) ($client->name ?? '—')],
                        $client?->phone ? ['الجوال', (string) $client->phone] : null,
                        $client?->national_id ? ['رقم الهوية', (string) $client->national_id] : null,
                    ]))],
                ],
                [
                    'title' => '٣. المبلغ',
                    'cellRows' => [
                        [['المبلغ', VoucherFormat::sar($halalas)]],
                        [['المبلغ كتابةً', ArabicAmount::riyals($halalas)]],
                    ],
                ],
                [
                    'title' => '٤. وذلك عن',
                    'cellRows' => [array_values(array_filter([
                        ['الفاتورة', (string) ($invoice->number ?? '—')],
                        ['البيان', (string) ($invoice->description ?? '—')],
                        ['طريقة القبض', $payment->methodLabel()],
                        $payment->gateway !== 'manual' ? ['مرجع الدفعة', (string) $payment->gateway_payment_id] : null,
                        $payment->note ? ['ملاحظة', (string) $payment->note] : null,
                    ]))],
                ],
            ],
            'approval' => [
                'title' => 'الاستلام',
                'rows' => [
                    ['المستلِم', $payment->receiverLabel()],
                    ['تاريخ القبض', VoucherFormat::date($receivedAt)],
                ],
            ],
            'note' => 'هذا السند إثباتٌ لاستلام المبلغ المذكور أعلاه، ولا يُغني عن الفاتورة الضريبيّة.',
        ]);
    }
}
