<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **الفاتورة تحمل تاريخ سدادها وضريبتها ومحطّات حياتها** (م١ من خطّة النظام الماليّ).
 *
 * `paid_at` أهمّ عمودٍ هنا: بدونه **لا يوجد تقريرٌ ماليّ بفترة في النظام** — لا «إيراد سبتمبر»
 * ولا «ضريبة الربع». البديل الوحيد كان `updated_at` وهو يتحرّك مع كلّ تعديلٍ غير ذي صلة
 * (تذكير، رفع إثبات، تصحيح وصف). وهو سبب حذف مؤشّرَي «صافي التدفّق» و«تغطية الرواتب» في م٠.
 *
 * و`subtotal`/`vat_rate`/`vat_amount`: كانت `invoices.amount` إجماليّاً شاملاً بلا أساسٍ ولا
 * ضريبة، فما يصدره النظام لم يكن فاتورةً ضريبيّة. والنسبة **تُجمَّد يوم الإصدار** على الصفّ ولا
 * تتبع `Setting::vatRate()` بعده — وإلّا تغيّرت ضريبة فواتير الماضي حين يعدّل المكتب النسبة.
 *
 * ── **التعبئة الرجعيّة: ما هو أصيلٌ وما هو تقريب** ──────────────────────────────────────
 *
 * | الحقل | المصدر | أصيل أم تقريب؟ |
 * |---|---|---|
 * | `issued_at` | `created_at` | **أصيل** — لا مفهومَ «مسوّدة» قبل اليوم، فكلّ فاتورةٍ قائمةٍ صادرة |
 * | `paid_at` | أحدث `payments.reconciled_at` مسوّىً للفاتورة | **أصيل** حيث وُجد الصفّ |
 * | `paid_at` | `updated_at` حين لا صفَّ دفعٍ مسوّى | **تقريب** — قد يسبق السداد أو يتأخّر عنه |
 * | ضريبة فاتورة استشارة | `consults.price` / `consults.vat` | **أصيل** حين يطابق مجموعُهما `amount` |
 * | ضريبة فاتورة تنفيذ | `executions.fee` / `executions.vat` | **أصيل** حين يطابق مجموعُهما `amount` |
 * | ضريبة فاتورة قضيّة | **عكس الحساب** من `amount` بنسبة الإعداد | **تقريب** |
 * | ضريبة دفعةِ تقسيطٍ أو أتعاب تحصيل | **عكس الحساب** من `amount` | **تقريب** — الصفّ الأمّ يحمل المجموع لا الحصّة |
 *
 * **لماذا القضايا بعكس الحساب؟** لأنّ الرقم الأصليّ ضاع **نصّاً**: `Transitions/LegalCase/SetFee`
 * يكتب الضريبة في `cases.invoice_text` عرضاً للقراءة، ولا عمود يحملها. وعكسُ الحساب يفترض أنّ
 * النسبة يوم الإصدار هي النسبة المضبوطة اليوم — وهو افتراضٌ قد يخطئ على فواتير سُعِّرت بنسبةٍ
 * سابقة. والفواتير **الجديدة** تحمل النسبة مجمَّدةً على صفّها، فلا يتكرّر التقريب بعد اليوم.
 *
 * وفي كلّ الأحوال يُكتب الحقلان بحيث `subtotal + vat_amount = amount` **بالضبط**: الفرق كلّه
 * يُحمَّل على الضريبة بعد التقريب لا قبله، فلا يضيع ريالٌ في الكسور.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // تاريخ السداد — بدونه لا تصفيةَ بفترةٍ على المال المحصَّل (ب١)
            $table->timestamp('paid_at')->nullable()->after('paid');

            // الأساس والنسبة والضريبة — والنسبة مجمَّدةٌ يوم الإصدار لا تتبع الإعداد
            $table->unsignedInteger('subtotal')->nullable()->after('amount');
            $table->unsignedTinyInteger('vat_rate')->nullable()->after('subtotal');
            $table->unsignedInteger('vat_amount')->nullable()->after('vat_rate');

            // محطّات دورة الحياة — تُكتب من انتقالات المحرّك (م٢)
            $table->timestamp('issued_at')->nullable()->after('paid_at');
            $table->timestamp('cancelled_at')->nullable()->after('issued_at');
            $table->timestamp('written_off_at')->nullable()->after('cancelled_at');
            $table->string('written_off_reason', 300)->nullable()->after('written_off_at');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'paid_at', 'subtotal', 'vat_rate', 'vat_amount',
                'issued_at', 'cancelled_at', 'written_off_at', 'written_off_reason',
            ]);
        });
    }

    /**
     * تعبئةٌ رجعيّة صفّاً صفّاً لا باستعلامٍ واحد: المنطق يتفرّع على نوع الفاتورة ومصدرها،
     * وجدول الفواتير صغيرٌ بطبيعته (فاتورةٌ لكلّ مطالبة) فلا ثمنَ للوضوح هنا.
     */
    private function backfill(): void
    {
        // نسبة الإعداد اليوم — هي كلّ ما يملكه عكسُ الحساب عن فواتير الماضي
        $rate = (int) (DB::table('settings')->where('key', 'vat_rate')->value('value') ?: 15);

        DB::table('invoices')->orderBy('id')->chunkById(200, function ($invoices) use ($rate) {
            foreach ($invoices as $invoice) {
                $amount = (int) $invoice->amount;

                [$subtotal, $vatAmount] = $this->taxOf($invoice, $amount, $rate);

                DB::table('invoices')->where('id', $invoice->id)->update([
                    'issued_at' => $invoice->created_at,
                    'paid_at' => $invoice->paid ? $this->paidAtOf($invoice) : null,
                    'subtotal' => $subtotal,
                    'vat_rate' => $rate,
                    'vat_amount' => $vatAmount,
                ]);
            }
        });
    }

    /**
     * الأساس والضريبة — من الصفّ الأصليّ متى طابق الإجماليَّ، وإلّا بعكس الحساب.
     *
     * @return array{0:int,1:int} [الأساس، الضريبة] ومجموعهما `amount` بالضبط
     */
    private function taxOf(object $invoice, int $amount, int $rate): array
    {
        $source = null;

        if ($invoice->consult_id !== null) {
            $source = DB::table('consults')->where('id', $invoice->consult_id)->first(['price as base', 'vat']);
        } elseif ($invoice->exec_id !== null) {
            $source = DB::table('executions')->where('id', $invoice->exec_id)->first(['fee as base', 'vat']);
        }

        // الصفّ الأصليّ لا يصلح إلّا إن طابق مجموعُه الفاتورةَ: دفعةُ تقسيطٍ حصّةٌ من الأتعاب
        // لا كلَّها، وفاتورةُ أتعابِ تحصيلٍ أساسُها المحصَّل لا `fee` — وكلتاهما تُعكَس حساباً.
        if ($source !== null && ((int) $source->base + (int) $source->vat) === $amount) {
            return [(int) $source->base, (int) $source->vat];
        }

        // **الضريبة تأخذ الباقي لا نصيبَها المقرَّب**: `subtotal + vat = amount` شرطٌ لا يُخرَق
        $subtotal = (int) round($amount * 100 / (100 + $rate));

        return [$subtotal, $amount - $subtotal];
    }

    /** تاريخ السداد: قيدُ الدفتر حيث وُجد، وإلّا `updated_at` **تقريباً** (انظر رأس المهاجرة). */
    private function paidAtOf(object $invoice): ?string
    {
        $reconciled = DB::table('payments')
            ->where('invoice_id', $invoice->id)
            ->where('status', 'paid')
            ->whereNotNull('reconciled_at')
            ->max('reconciled_at');

        return $reconciled ?: $invoice->updated_at;
    }
};
