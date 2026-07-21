<?php

namespace App\Http\Controllers;

use App\Events\CaseStatusBroadcast;
use App\Jobs\DraftCasePleadingJob;
use App\Jobs\GenerateCaseReplyJob;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Services\LegalAiService;
use App\Support\CaseJourney;
use App\Support\Live;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class CaseController extends Controller
{
    public function __construct(private LegalAiService $ai) {}

    // قائمة قضايا العميل الحالي
    public function index(Request $request): Response
    {
        $cases = LegalCase::where('user_id', $request->user()->id)->latest('id')->get()
            ->map(fn (LegalCase $c) => $c->toCard());

        return Inertia::render('cases', [
            'cases' => $cases,
        ]);
    }

    // متابعة قضية واحدة
    public function show(Request $request, LegalCase $case): Response
    {
        $this->authorizeCase($request, $case);
        $case->load('hearings');

        return Inertia::render('casechat', [
            'case' => [
                'no' => $case->number,
                'type' => $case->type,
                'status' => $case->status,
                'tone' => $case->tone,
                'update' => $case->update_text,
                'next' => $case->next_hearing,
                'invoice' => $case->invoice_text,
                'paid' => $case->paid_text,
                'fee' => $case->fee,
                'feeStatus' => $case->fee_status,
                'installmentsPaid' => $case->installments_paid,
                'installmentsTotal' => $case->installments_total,
            ],
            'channel' => 'case.'.$case->id,
            'messages' => $case->messages->where('who', '!=', 'note')->values()->map->toMessage(),
            'hearings' => $case->hearings->map->toData(),
        ]);
    }

    // إرسال رسالة من العميل + ردّ تلقائي من الفريق (بثّ لحظي بلا إعادة تحميل)
    public function storeMessage(Request $request, LegalCase $case): HttpResponse
    {
        $this->authorizeCase($request, $case);

        abort_if(
            in_array($case->status, ['مغلقة', 'مؤرشفة'], true),
            422,
            'لا يمكن إرسال رسائل على قضية مغلقة أو مؤرشفة.'
        );

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $case->messages()->create([
            'who' => 'client',
            'name' => 'أنت',
            'role' => 'العميل',
            'body' => e($data['body']),
            'time_label' => $this->clock(),
        ]);

        $body = $data['body'];
        GenerateCaseReplyJob::dispatch($case, $body);

        return response()->noContent();
    }

    // سداد أتعاب القضية (كامل أو على دفعات) → تفعيلها (يطابق cfPay/activate)
    public function pay(Request $request, LegalCase $case): RedirectResponse
    {
        $this->authorizeCase($request, $case);
        abort_unless($case->fee_status === 'pending_payment', 422);

        $plan = $request->validate(['plan' => ['nullable', 'in:full,install']])['plan'] ?? 'full';

        if ($plan === 'install') {
            $case->update([
                'pay_plan' => 'install',
                'installments_total' => 3,
                'installments_paid' => 1,
                'fee_status' => 'installments',
                'paid_text' => 'دفعة 1 من 3 مدفوعة',
            ]);
            $case->messages()->create([
                'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
                'body' => '<p>تم استلام الدفعة الأولى (1 من 3) من أتعاب القضية، وتفعيلها. تُسدَّد بقية الدفعات لاحقاً.</p>',
                'time_label' => $this->clock(),
            ]);
        } else {
            $case->update(['pay_plan' => 'full', 'fee_status' => 'paid', 'paid_text' => 'تم سداد كامل الأتعاب']);
            $this->markInvoicePaid($case);
            $case->messages()->create([
                'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
                'body' => '<p>تم استلام سداد كامل الأتعاب وتفعيل القضية.</p>',
                'time_label' => $this->clock(),
            ]);
        }

        $this->activate($case);

        return back();
    }

    // سداد دفعة تالية من الأقساط
    public function payInstallment(Request $request, LegalCase $case): RedirectResponse
    {
        $this->authorizeCase($request, $case);
        abort_unless($case->fee_status === 'installments', 422);

        $paid = $case->installments_paid + 1;
        $done = $paid >= $case->installments_total;
        $case->update([
            'installments_paid' => $paid,
            'fee_status' => $done ? 'paid' : 'installments',
            'paid_text' => $done ? 'تم سداد كامل الأتعاب' : "دفعة {$paid} من {$case->installments_total} مدفوعة",
        ]);
        if ($done) {
            $this->markInvoicePaid($case);
        }
        $case->messages()->create([
            'who' => 'system', 'name' => 'النظام', 'role' => 'سداد',
            'body' => $done
                ? '<p>تم سداد الدفعة الأخيرة واكتمال أتعاب القضية.</p>'
                : "<p>تم استلام الدفعة {$paid} من {$case->installments_total}.</p>",
            'time_label' => $this->clock(),
        ]);

        return back();
    }

    // تفعيل القضية: خطة العمل + مسودة اللائحة (مرّة واحدة عند أول سداد)
    private function activate(LegalCase $case): void
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
            'time_label' => $this->clock(),
        ]);

        $steps = ['إعداد اللائحة', 'تجهيز المستندات', 'رفع الدعوى', 'متابعة الجلسات', 'متابعة الحكم', 'التنفيذ'];
        $stepsHtml = implode('', array_map(fn ($s) => '<li>'.e($s).'</li>', $steps));
        $case->messages()->create([
            'who' => 'ai', 'name' => 'المساعد القانوني', 'role' => 'خطة العمل',
            'body' => '<p>خطة العمل المقترحة للقضية:</p><div class="result-card"><div class="result-sec"><div class="t">مراحل القضية</div><ul>'.$stepsHtml.'</ul></div></div>',
            'time_label' => $this->clock(),
        ]);

        DraftCasePleadingJob::dispatch($case);

        Live::push(new CaseStatusBroadcast($case));
    }

    private function markInvoicePaid(LegalCase $case): void
    {
        Invoice::where('case_id', $case->id)->where('paid', false)
            ->update(['paid' => true, 'status' => 'مدفوعة', 'tone' => 'b-green']);
    }

    private function authorizeCase(Request $request, LegalCase $case): void
    {
        abort_unless($case->user_id === $request->user()->id, 403);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
