<?php

namespace App\Support;

use App\Events\CaseStatusBroadcast;
use App\Jobs\DraftCasePleadingJob;
use App\Mail\CaseFeePaidMail;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Services\MailService;
use App\Services\MoyasarService;
use Illuminate\Support\Facades\DB;

/**
 * دورة سداد أتعاب القضية وتفعيلها — مصدر موحّد يخدم الدفع المحاكى ودفع Moyasar (webhook/callback).
 * markPaid وactivate كلاهما idempotent (تكرار إشعار البوّابة لا يُحدث أثرًا مزدوجًا).
 */
class CaseFee
{
    /** سداد كامل الأتعاب وتفعيل القضية — idempotent وآمن ضدّ التسابق (قفل الصفّ). */
    public static function markPaid(LegalCase $case): void
    {
        // قفل الصفّ ثم إعادة فحص الحالة — يمنع تسابق webhook+callback
        $didPay = DB::transaction(function () use ($case) {
            $locked = LegalCase::whereKey($case->id)->lockForUpdate()->first();
            if ($locked === null || $locked->fee_status === 'paid') {
                return false;
            }

            $locked->update(['pay_plan' => 'full', 'fee_status' => 'paid', 'paid_text' => 'تم سداد كامل الأتعاب']);
            self::markInvoicePaid($locked);

            return true;
        });

        if (! $didPay) {
            return;
        }

        $case->refresh();
        $case->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
            'body' => '<p>تم استلام سداد كامل الأتعاب وتفعيل القضية.</p>',
            'time_label' => self::clock(),
        ]);
        self::activate($case);

        // بريد للعميل بتأكيد سداد الأتعاب وتفعيل القضية (أفضل-جهد — لا يعطّل مسار الدفع إن فشل)
        $case->loadMissing('user');
        if ($case->user?->email) {
            app(MailService::class)->send($case->user, new CaseFeePaidMail($case));
        }
    }

    /** يعلّم فواتير أتعاب القضية غير المدفوعة مدفوعةً. */
    public static function markInvoicePaid(LegalCase $case): void
    {
        Invoice::where('case_id', $case->id)->where('paid', false)
            ->update(['paid' => true, 'status' => 'مدفوعة', 'tone' => 'b-green']);
    }

    /** تفعيل القضية: خطة العمل + مسودة اللائحة (مرّة واحدة عند أول سداد). */
    public static function activate(LegalCase $case): void
    {
        if ($case->pleading_status !== 'none') {
            return; // فُعّلت سابقاً
        }

        $case->update([
            'status' => 'قيد التحضير',
            'tone' => CaseJourney::toneFor('قيد التحضير'),
            'update_text' => 'تم تفعيل القضية؛ يجهّز الفريق خطة العمل واللائحة',
            'pleading_status' => 'pending_lawyer',
        ]);

        $lawyer = $case->assigned_lawyer ?: 'المستشار القانوني';
        $case->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => 'تفعيل',
            'body' => '<p>تم تفعيل القضية وإسنادها إلى '.e($lawyer).'.</p>',
            'time_label' => self::clock(),
        ]);

        $steps = ['إعداد اللائحة', 'تجهيز المستندات', 'رفع الدعوى', 'متابعة الجلسات', 'متابعة الحكم', 'التنفيذ'];
        $stepsHtml = implode('', array_map(fn ($s) => '<li>'.e($s).'</li>', $steps));
        $case->messages()->create([
            'who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'خطة العمل',
            'body' => '<p>خطة العمل المقترحة للقضية:</p><div class="result-card"><div class="result-sec"><div class="t">مراحل القضية</div><ul>'.$stepsHtml.'</ul></div></div>',
            'time_label' => self::clock(),
        ]);

        DraftCasePleadingJob::dispatch($case);

        Live::push(new CaseStatusBroadcast($case));
    }

    /**
     * يبدأ دفعة ميسّر مستضافة لفاتورة أتعاب القضية (السداد الكامل) ويعيد رابط الدفع أو null.
     * يخزّن معرّف فاتورة البوّابة على الفاتورة للمطابقة عند العودة/الـwebhook.
     */
    public static function initiatePayment(LegalCase $case, string $callbackUrl): ?string
    {
        $invoice = Invoice::where('case_id', $case->id)->where('paid', false)->latest('id')->first();

        return $invoice ? app(MoyasarService::class)->hostedUrlForInvoice($invoice, $callbackUrl) : null;
    }

    private static function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
