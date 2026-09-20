<?php

namespace App\Support\Finance;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Workflow;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Support\InvoiceNumber;

/**
 * **إصدار الفاتورة — المصدر الواحد لحقولها الضريبيّة** (م١ من خطّة النظام الماليّ).
 *
 * كانت الفاتورة تُنشأ في **ستّة مواضع** (`Consult/PriceConsult` · `Admin/CaseController` ·
 * `CaseFee` · `ExecFee` × ٣)، كلٌّ يبني مصفوفته بيده ويحسب — أو لا يحسب — ضريبته. فإضافة
 * `subtotal`/`vat_rate`/`vat_amount` بتلك الطريقة تعني ستّ نسخٍ من حسابٍ واحد تتباعد عند أوّل
 * تعديل. هنا يُكتب الحساب مرّةً، وتناديه المواضع الستّة كلّها.
 *
 * ── **القاعدتان الحاكمتان** ─────────────────────────────────────────────────────────────
 *
 * **١. `subtotal + vat_amount = amount` بالضبط، دائماً.** لا كسرَ يضيع ولا ريالَ يُخترع:
 * التقريب يقع على أحد الطرفين، والآخر يأخذ **الباقي** لا نصيبَه المحسوب. ويحرسه اختبارٌ على
 * مواضع الإصدار الستّة.
 *
 * **٢. `vat_rate` مجمَّدةٌ يوم الإصدار.** تُنسخ من `Setting::vatRate()` إلى الصفّ ولا تتبعه
 * بعد ذلك. ولو قُرئت من الإعداد عند العرض لتغيّرت ضريبةُ فواتير الماضي كلّها حين يعدّل المكتب
 * النسبة — أي لتغيّر مستندٌ ضريبيٌّ سلّمه المكتب للعميل.
 *
 * ── **ثلاثة مداخل لأنّ المنادين ثلاثة أصناف** ───────────────────────────────────────────
 *
 * | المدخل | ماذا يملك المنادي؟ | مَن يناديه |
 * |---|---|---|
 * | `fromBase` | **الأساس**، والقرار يقع الآن | `ExecFee::issueCollectionFee` (نسبةٌ من محصَّلٍ تُحسب لحظتَها) |
 * | `fromFrozen` | **الأساس والضريبة** مقرَّرين سلفاً على صفٍّ آخر | `PriceConsult` · `Admin/CaseController` · `ExecFee::openOnAcceptance` |
 * | `fromTotal` | **الإجماليَّ وحده** | خطّتا التقسيط في `CaseFee` و`ExecFee` (المجموع يُقسَّم لا الأساس) |
 *
 * **ولماذا `fromFrozen` بدل إعادة الحساب؟** لأنّ الرقم أُعلن للعميل قبل الفوترة: عرضُ أتعاب
 * تنفيذٍ يُعتمد ثمّ يقبله العميل بعد أيّام، والاستشارةُ تُسعَّر ويُرسَل إجماليُّها في إشعار.
 * فلو حُسبت الضريبة من نسبة **اليوم** لصدرت فاتورةٌ بمبلغٍ غير الذي قَبِله العميل كلّما عدّل
 * المكتب النسبة بينهما. والمُجمَّد يُنسَخ كما هو، وتُستنتَج نسبتُه منه.
 *
 * ── **ولماذا `Workflow::open`؟** ────────────────────────────────────────────────────────
 *
 * إنشاء الفاتورة يكتب `status` و`paid`، وهما عمودان مراقَبان في `StateWriteGuard`. فبعد رفع
 * استثناء «كلّ فاتورةٍ ليست فاتورة استشارة» (م٢) يصير كلّ إنشاءٍ خارج المحرّك كتابةً مرفوضة.
 * والفتح داخل المحرّك يبدأ سجلّ الفاتورة بسطرٍ (`from_state = null`) كما تبدأ كلُّ رحلةٍ أخرى.
 */
final class InvoiceFactory
{
    /** اسم سطر الفتح في `journey_transitions` — بدايةُ رحلة كلّ فاتورة. */
    public const OPENED = 'invoice.opened';

    /**
     * **إصدارٌ من أساسٍ معلوم قبل الضريبة.** الضريبة تُحسب عليه، والإجماليّ مجموعُهما —
     * فلا يمرَّر `amount` في `$attributes`، وما مُرِّر منه يُتجاهَل لصالح المحسوب.
     *
     * @param  array<string, mixed>  $attributes  بقيّة حقول الفاتورة (`user_id` · الروابط · `description` · الاستحقاق)
     */
    public static function fromBase(int $base, array $attributes, ?User $actor = null): Invoice
    {
        return self::issue(self::taxFromBase($base), $attributes, $actor);
    }

    /**
     * **إصدارٌ من أساسٍ وضريبةٍ مجمَّدتين سلفاً** على صفّ الاستشارة أو القضيّة أو التنفيذ —
     * تُنسخان كما هما، فلا يتغيّر مبلغٌ أُعلن للعميل حين تُعدَّل نسبة الإعداد بعده.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromFrozen(int $base, int $vat, array $attributes, ?User $actor = null): Invoice
    {
        return self::issue(self::taxFromFrozen($base, $vat), $attributes, $actor);
    }

    /**
     * **إصدارٌ من إجماليٍّ معلوم** — الأساس والضريبة بعكس الحساب. موضعُه: حصّةُ دفعةٍ من خطّة
     * تقسيط، حيث يُقسَّم الإجماليُّ المفوتَر لا الأساسُ.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function fromTotal(int $total, array $attributes, ?User $actor = null): Invoice
    {
        return self::issue(self::taxFromTotal($total), $attributes, $actor);
    }

    /**
     * حقول المال من أساسٍ وضريبةٍ مجمَّدتين — بلا إنشاء.
     *
     * **والنسبة تُستنتَج من الرقمين لا من الإعداد**: هي النسبة التي طُبّقت فعلاً يوم التسعير،
     * وهي ما يجب أن يُطبع على المستند الضريبيّ. وأساسٌ بصفرٍ (لا يقع في مواضع الإصدار الستّة)
     * لا نسبةَ تُستنتَج منه، فيعود إلى الإعداد.
     *
     * @return array{amount:int, subtotal:int, vat_rate:int, vat_amount:int}
     */
    public static function taxFromFrozen(int $base, int $vat): array
    {
        return [
            'amount' => $base + $vat,
            'subtotal' => $base,
            'vat_rate' => $base > 0 ? (int) round($vat * 100 / $base) : Setting::vatRate(),
            'vat_amount' => $vat,
        ];
    }

    /**
     * حقول المال من أساسٍ معلوم — بلا إنشاء. لمن يعيد تسعير فاتورةٍ قائمة لا يُصدر جديدة.
     *
     * @return array{amount:int, subtotal:int, vat_rate:int, vat_amount:int}
     */
    public static function taxFromBase(int $base): array
    {
        $rate = Setting::vatRate();
        $vat = (int) round($base * $rate / 100);

        return ['amount' => $base + $vat, 'subtotal' => $base, 'vat_rate' => $rate, 'vat_amount' => $vat];
    }

    /**
     * حقول المال من إجماليٍّ معلوم — بلا إنشاء. يناديها فاتحُ خطّة التقسيط حين يُعيد هيكلة
     * الفاتورة الأمّ إلى حصّةٍ أصغر: لولا ذلك بقيت تحمل ضريبةَ المبلغ الكامل.
     *
     * **الضريبة تأخذ الباقي:** لو حُسب الطرفان كلٌّ على حدةٍ لاختلّ المجموع بريالٍ في الكسور.
     *
     * @return array{amount:int, subtotal:int, vat_rate:int, vat_amount:int}
     */
    public static function taxFromTotal(int $total): array
    {
        $rate = Setting::vatRate();
        $subtotal = (int) round($total * 100 / (100 + $rate));

        return ['amount' => $total, 'subtotal' => $subtotal, 'vat_rate' => $rate, 'vat_amount' => $total - $subtotal];
    }

    /**
     * @param  array{amount:int, subtotal:int, vat_rate:int, vat_amount:int}  $money
     * @param  array<string, mixed>  $attributes
     */
    private static function issue(array $money, array $attributes, ?User $actor): Invoice
    {
        // الافتراضات هي ما كانت مكرّرةً في المواضع الستّة حرفاً — والمنادي يدهسها عند الحاجة.
        $row = array_merge([
            'number' => null,
            'status' => InvoiceStatus::Due->value,
            'tone' => 'b-amber',
            'paid' => false,
        ], $attributes, $money);

        // رقمٌ يُسَكّ **داخل** المعاملة فلا يُستهلك رقمٌ لإنشاءٍ تراجع (نمط `ExecFee::openOnAcceptance`)
        return Workflow::open(
            self::OPENED,
            function () use ($row): Invoice {
                $row['number'] ??= InvoiceNumber::next();
                $row['issued_at'] ??= now();

                return Invoice::create($row);
            },
            $actor,
            ['amount' => $money['amount'], 'vat_amount' => $money['vat_amount']],
        );
    }
}
