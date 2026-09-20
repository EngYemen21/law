<?php

namespace App\Support\Finance;

use App\Models\Invoice;
use App\Support\ReportPrint;
use App\Support\SettingsRegistry;
use Illuminate\Support\Carbon;

/**
 * **الفاتورة الضريبيّة المطبوعة — مستندٌ واحد يعرفه النظام كلّه** (م١).
 *
 * كان المطبوع سطراً واحداً بالإجماليّ الشامل داخل `InvoiceController::pdf`: لا أساسَ قبل
 * الضريبة، ولا ضريبةً بنسبتها، ولا رقماً ضريبيّاً للمكتب — فما يصدره النظام لم يكن فاتورةً
 * ضريبيّة (ب٢ في خطّة النظام الماليّ).
 *
 * **ولماذا صنفٌ لا دالّةٌ في المتحكّم؟** لأنّ المستند سيُطبع من موضعين على الأقلّ: شاشة العميل
 * اليوم، وتبويب الفواتير في م٣ — ونسختان من مستندٍ ضريبيّ تتباعدان عند أوّل تعديل. ولأنّ
 * محتواه يصير عندئذٍ قابلاً للفحص نصّاً، فلا يُختبَر بفتح ملفّ PDF ثنائيّ.
 *
 * **ولا رمز استجابةٍ سريعاً.** في المشروع مولّد رمزٍ **زخرفيّ** يعترف تعليقه بأنّه لا يشفّر
 * شيئاً (`Support\AppointmentCard`)، ورمزٌ كاذب على مستندٍ ضريبيّ أسوأ من غيابه. والرمز
 * النظاميّ جزءٌ من الفوترة الإلكترونيّة المؤجَّلة بقرار المالك (ق١).
 */
final class TaxInvoiceDocument
{
    public static function html(Invoice $invoice): string
    {
        $money = $invoice->taxBreakdown();
        $client = $invoice->user;
        $issuedAt = $invoice->issued_at ?? $invoice->created_at;

        return ReportPrint::html([
            'title' => 'فاتورة ضريبيّة',
            // الحالة الحيّة لا «مدفوعة/مستحقة» وحدهما: المتأخّرة والملغاة تُطبعان بما هما
            'subtitle' => $invoice->liveStatus()[0],
            'ref' => (string) $invoice->number,
            'blocks' => [
                ['title' => '١. بيانات المكتب', 'cellRows' => [self::officeCells($issuedAt)]],
                [
                    'title' => '٢. بيانات العميل',
                    'cellRows' => [array_values(array_filter([
                        ['الاسم', (string) ($client?->name ?? '—')],
                        $client?->phone ? ['الجوال', (string) $client->phone] : null,
                        $client?->national_id ? ['رقم الهوية', (string) $client->national_id] : null,
                    ]))],
                ],
                [
                    'title' => '٣. بيانات الفاتورة',
                    'cellRows' => [[
                        ['رقم الفاتورة', (string) $invoice->number],
                        ['الوصف', (string) $invoice->description],
                        // التاريخ الحقيقيّ لا «خلال 3 أيام» المجمَّدة لحظة الإصدار — `Invoice::dueDateText`
                        ['تاريخ الاستحقاق', $invoice->dueDateText() ?? '—'],
                        ['حالة السداد', $invoice->paid ? 'مدفوعة' : (string) $invoice->status],
                    ]],
                ],
                [
                    // **ثلاثة سطورٍ منفصلة**: الأساس، ثمّ الضريبة بنسبتها، ثمّ الإجماليّ —
                    // ومجموع الأوّلين هو الثالث بالضبط (`InvoiceFactory`).
                    'title' => '٤. تفصيل المبلغ',
                    'cellRows' => [
                        [[self::SUBTOTAL_LABEL, self::money($money['subtotal'])]],
                        [['ضريبة القيمة المضافة ('.$money['vat_rate'].'%)', self::money($money['vat_amount'])]],
                        [[self::TOTAL_LABEL, self::money($money['amount'])]],
                    ],
                ],
            ],
            'footer' => 'النظام الإداري لمكاتب المحاماة — شكراً لتعاملكم معنا',
        ]);
    }

    public const SUBTOTAL_LABEL = 'الإجمالي قبل الضريبة';

    public const TOTAL_LABEL = 'الإجمالي شامل الضريبة';

    public const VAT_NUMBER_LABEL = 'الرقم الضريبيّ للمكتب';

    /**
     * **الرقم الضريبيّ إمّا صحيحٌ وإمّا لا سطر.** الفارغ لا يُطبع بشرطةٍ ولا برقمٍ منقوش:
     * مكتبٌ غير مسجَّل في ضريبة القيمة المضافة لا يُنسب إليه تسجيل، ورقمٌ وهميّ على مستندٍ
     * رسميّ أسوأ من غيابه.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function officeCells(?\DateTimeInterface $issuedAt): array
    {
        $cells = [['اسم المكتب', SettingsRegistry::str('office_name')]];

        $vatNumber = SettingsRegistry::str('office_vat_number');
        if ($vatNumber !== '') {
            $cells[] = [self::VAT_NUMBER_LABEL, $vatNumber];
        }

        $cells[] = ['تاريخ الإصدار', $issuedAt !== null
            ? Carbon::instance($issuedAt)->locale('ar')->translatedFormat('d F Y')
            : '—'];

        return $cells;
    }

    private static function money(int $amount): string
    {
        return number_format($amount).' ر.س';
    }
}
