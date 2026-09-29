<?php

namespace App\Support\Finance;

use App\Models\Invoice;
use App\Support\SettingsRegistry;
use Illuminate\Support\Carbon;

/**
 * **رمز الفاتورة الضريبيّة المبسَّطة — صيغة هيئة الزكاة والضريبة والجمارك** (المرحلة الأولى من
 * الفوترة الإلكترونيّة: «الإصدار»). المصدر الوحيد للحمولة؛ يناديه كلّ من يطبع فاتورةً ضريبيّة.
 *
 * يُرسم الرمز بالمولّد الوحيد (`Support\Qr`) عبر كتلة الذيل في `ReportPrint`.
 *
 * الحمولة **TLV ثمّ Base64**: لكلّ حقلٍ بايتُ الوسم، ثمّ بايتُ الطول (طول القيمة **بالبايت** في
 * UTF-8 لا بالحروف — «مكتب» أربعة حروف وثمانية بايتات)، ثمّ القيمة. والوسوم الخمسة بترتيبها:
 *
 * | الوسم | الحقل | المصدر هنا |
 * |---|---|---|
 * | 1 | اسم البائع | `office_name` من الإعدادات |
 * | 2 | الرقم الضريبيّ | `office_vat_number` من الإعدادات |
 * | 3 | وقت إصدار الفاتورة ISO 8601 | `issued_at` (وإلّا `created_at`) بتوقيتٍ عالميّ |
 * | 4 | الإجماليّ شامل الضريبة | `amount` |
 * | 5 | مبلغ الضريبة | `vat_amount` |
 *
 * **الوحدات: ريالاتٌ صحيحة.** أعمدة الفاتورة (`amount`/`subtotal`/`vat_amount`) بالريال
 * (`InvoiceFactory`، و`PaymentReconciler` يضربها في ١٠٠ ليكتب `amount_halalas`)، فتُكتب هنا
 * بمنزلتين عشريّتين كما تطلب الهيئة (`1150.00`) — لا قسمة على ١٠٠، وإلّا صار الرمز يُعلن
 * عُشر العُشر ممّا في الفاتورة.
 *
 * **ولا رمز بلا رقمٍ ضريبيّ.** الافتراض فارغ عمداً (`SettingsRegistry`): مكتبٌ غير مسجَّل لا يُنسب
 * إليه تسجيل. ورمزٌ بلا وسم ٢ ليس رمزاً ضريبيّاً، فيُحذف كلّه — كما يُحذف سطر الرقم من المستند.
 * وكذلك الفاتورة الملغاة: رمزٌ يُعلن ضريبةً مستحقّة على فاتورةٍ أُلغيت إقرارٌ كاذب.
 */
final class ZatcaQr
{
    public const TAG_SELLER = 1;

    public const TAG_VAT_NUMBER = 2;

    public const TAG_TIMESTAMP = 3;

    public const TAG_TOTAL = 4;

    public const TAG_VAT = 5;

    /** الحمولة Base64، أو `null` حين لا يصحّ إصدار رمزٍ ضريبيّ لهذه الفاتورة. */
    public static function payload(Invoice $invoice): ?string
    {
        $fields = self::fields($invoice);

        return $fields === null ? null : self::encode($fields);
    }

    /**
     * قيم الوسوم الخمسة نصّاً — ما يُرمَّز بعينه. تُعرض أيضاً بجوار الرمز في المستند، فيرى القارئ
     * ما يحمله الرمز دون ماسح.
     *
     * @return array<int<1, 5>, string>|null
     */
    public static function fields(Invoice $invoice): ?array
    {
        $vatNumber = SettingsRegistry::str('office_vat_number');
        $issuedAt = $invoice->issued_at ?? $invoice->created_at;

        if ($vatNumber === '' || $issuedAt === null || $invoice->isCancelled()) {
            return null;
        }

        $money = $invoice->taxBreakdown();

        return [
            self::TAG_SELLER => SettingsRegistry::str('office_name'),
            self::TAG_VAT_NUMBER => $vatNumber,
            self::TAG_TIMESTAMP => Carbon::instance($issuedAt)->utc()->format('Y-m-d\TH:i:s\Z'),
            self::TAG_TOTAL => number_format($money['amount'], 2, '.', ''),
            self::TAG_VAT => number_format($money['vat_amount'], 2, '.', ''),
        ];
    }

    /**
     * ترميز TLV ثمّ Base64.
     *
     * بايت الطول يحتمل ٢٥٥ بايتاً فقط؛ واسم المكتب مقيَّدٌ بـ١٢٠ حرفاً (أي ٢٤٠ بايتاً عربيّاً)،
     * فالقطع هنا حارسٌ للحدّ النظريّ لا مسارٌ متوقَّع — ويقطع على حدّ حرفٍ كامل لا وسط بايتاته.
     *
     * @param  array<int<1, 255>, string>  $fields  الوسم بايتٌ واحد (ZATCA: 1–5)
     */
    public static function encode(array $fields): string
    {
        $tlv = '';
        foreach ($fields as $tag => $value) {
            $bytes = mb_strcut($value, 0, 255, 'UTF-8');
            // الطول بايتٌ واحد في TLV — و`mb_strcut` أعلاه يضمن ألّا يتجاوز ٢٥٥
            $tlv .= chr($tag).chr(min(255, strlen($bytes))).$bytes;
        }

        return base64_encode($tlv);
    }
}
