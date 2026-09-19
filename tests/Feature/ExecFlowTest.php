<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\AnalyzeExecutionJob;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\User;
use App\Support\ExecFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * تدفّق طلب التنفيذ التجاريّ (10 مراحل، نظير EXEC_REQS) — التقديم والتحليل والتسعير والعرض والسداد والإجراءات،
 * مع حراسة الدور/الملكيّة لكلّ انتقال. لا بيانات وهميّة — كلّ الحالة من الخادم.
 */
class ExecFlowTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function lawyer(): User
    {
        return User::factory()->create(['role' => Role::Lawyer]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    /**
     * تحليل ذكيّ ناجح مُحاكى. كانت هذه الاختبارات تمضي بلا مزوّد فيعود القالب
     * الاحتياطيّ، وكان هو من يرفع المرحلة إلى «قيد الدراسة» — أي أنها كانت توثّق
     * تقدّم الطلب بناءً على قالب لم يفحص مستنداً. المرحلة لم تعد ترتفع إلا بتحليل
     * فعليّ (أو بإحالة إداريّة صريحة)، فيُحاكى المزوّد هنا ليختبر المسار الحقيقيّ.
     */
    private function fakeAiSuccess(array $payload = []): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush(); // لا تهدئة عالقة من اختبار سابق
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode(array_merge([
                        'summary' => 'سند تنفيذيّ مستوفٍ بعد فحص المرفقات.',
                        'missing' => [],
                        'procedures' => ['تقديم طلب تنفيذ إلكتروني'],
                    ], $payload), JSON_UNESCAPED_UNICODE)]]],
                ]],
            ], 200),
        ]);
    }

    /** التقديم وحده — لمن يهيّئ مزوّده بنفسه. */
    private function postSubmit(User $client, array $over = []): Execution
    {
        $this->actingAs($client)->post('/exec-flow', array_merge([
            'sanad' => 'شيك', 'subject' => 'تحصيل قيمة شيك مرتجع', 'defendant' => 'مؤسسة الرمال', 'amount' => 85000,
        ], $over))->assertRedirect();

        return Execution::where('user_id', $client->id)->latest('id')->firstOrFail();
    }

    private function submit(User $client, array $over = [], array $ai = []): Execution
    {
        $this->fakeAiSuccess($ai);

        return $this->postSubmit($client, $over);
    }

    private function act(User $actor, Execution $e, string $action, array $payload = []): void
    {
        $this->actingAs($actor)->post("/exec-flow/{$e->number}/action", array_merge(['action' => $action], $payload))->assertRedirect();
    }

    /** يبلغ بالطلب إلى مرحلة «عرض الخدمة» (5): تقديم → قبول → أتعاب → اعتماد. */
    private function reachOffer(User $client): Execution
    {
        $lawyer = $this->lawyer();
        $admin = $this->admin();
        $exec = $this->submit($client);
        $this->act($lawyer, $exec, 'accept');
        $this->act($lawyer, $exec, 'saveFee', ['fee' => 6000]);
        $this->act($admin, $exec, 'approveFee');

        return $exec->refresh();
    }

    private function configureMoyasar(): void
    {
        config(['services.moyasar.secret_key' => 'sk_test_x', 'services.moyasar.webhook_secret' => 'whsec_1']);
    }

    public function test_client_submit_analyzes_and_reaches_study_when_complete(): void
    {
        $client = $this->client();
        $exec = $this->submit($client);

        $this->assertSame(2, $exec->stage);           // مكتمل (يوجد منفَّذ ضده) → قيد الدراسة
        $this->assertTrue((bool) $exec->ai_done);
        $this->assertSame([], $exec->ai_missing);
        $this->assertNotEmpty($exec->ai_procedures);
        $this->assertStringStartsWith('EXE-', $exec->number);
    }

    public function test_missing_defendant_holds_at_analysis_then_admin_refers(): void
    {
        $client = $this->client();
        // تحليل فعليّ رصد نقصاً — لا قالب احتياطيّ: المطلوب إثبات أن النواقص تحبس
        // الطلب في مرحلة التحليل حتى يُحيله إنسان، لا إثبات ما يفعله الاحتياطيّ.
        $exec = $this->submit($client, ['defendant' => ''], ['missing' => ['بيانات المنفَّذ ضده']]);
        $this->assertSame(1, $exec->stage);           // نواقص → تحليل ذكي
        $this->assertContains('بيانات المنفَّذ ضده', $exec->ai_missing);

        $this->act($this->admin(), $exec, 'refer');
        $this->assertSame(2, $exec->refresh()->stage);
    }

    public function test_full_lifecycle_submit_to_close(): void
    {
        $client = $this->client();
        $lawyer = $this->lawyer();
        $admin = $this->admin();

        $exec = $this->submit($client);               // stage 2

        $this->act($lawyer, $exec, 'accept');
        $this->assertSame(3, $exec->refresh()->stage);
        $this->assertSame($lawyer->id, $exec->assigned_lawyer_id); // أُسند للمحامي عند القبول

        $this->act($lawyer, $exec, 'saveFee', ['fee' => 6000, 'duration' => '30 يوم', 'payMethod' => 'دفعة واحدة']);
        $exec->refresh();
        $this->assertSame(4, $exec->stage);
        $this->assertSame(6000, $exec->fee);
        $this->assertSame(900, $exec->vat);           // 15%

        $this->act($admin, $exec, 'approveFee');
        $exec->refresh();
        $this->assertSame(5, $exec->stage);
        $this->assertTrue((bool) $exec->fee_approved);

        $this->act($client, $exec, 'acceptOffer');
        $exec->refresh();
        $this->assertSame(6, $exec->stage);
        $invoice = Invoice::where('exec_id', $exec->id)->firstOrFail();
        $this->assertSame(6900, $invoice->amount);
        $this->assertFalse((bool) $invoice->paid);

        // السداد يمرّ بميسّر؛ نحاكي تسوية البوّابة باستدعاء markPaid مباشرةً (phpunit بلا مفاتيح)
        ExecFee::settleInvoice($exec->refresh());
        $exec->refresh();
        $this->assertSame(7, $exec->stage);           // سُدّدت الأتعاب — بانتظار الرفع في ناجز
        $this->assertTrue((bool) $exec->paid);
        $this->assertNotEmpty($exec->exec_no);
        $this->assertTrue((bool) Invoice::where('exec_id', $exec->id)->first()->paid);
        $this->assertSame(1, $exec->procedures()->count()); // إجراء فتح الملف

        $this->act($lawyer, $exec, 'addProcedure', ['title' => 'تم الحجز على الحساب البنكي']);
        $this->assertSame(2, $exec->refresh()->procedures()->count());

        $this->act($admin, $exec, 'close', ['reason' => 'سداد كامل']);
        $this->assertSame(9, $exec->refresh()->stage);
        $this->assertSame('سداد كامل', $exec->refresh()->closed_reason); // كيف انتهى الحقّ يُسجَّل
    }

    public function test_markpaid_is_idempotent(): void
    {
        $client = $this->client();
        $exec = $this->reachOffer($client);
        $this->act($client, $exec, 'acceptOffer');

        ExecFee::settleInvoice($exec->refresh());
        $execNo = $exec->refresh()->exec_no;
        ExecFee::settleInvoice($exec->refresh()); // تكرار (نظير webhook+callback)

        $this->assertSame($execNo, $exec->refresh()->exec_no); // لم يتغيّر
        $this->assertSame(7, $exec->refresh()->stage);
        $this->assertSame(1, $exec->procedures()->count());    // إجراء فتح الملف مرّة واحدة
    }

    public function test_role_authorization_is_enforced(): void
    {
        $client = $this->client();
        $exec = $this->submit($client);

        // العميل لا يعتمد الأتعاب، والمحامي لا يدفع (مسار ميسّر مخصّص)، وغير المالك لا يقبل العرض
        $this->actingAs($client)->post("/exec-flow/{$exec->number}/action", ['action' => 'approveFee'])->assertForbidden();
        $this->actingAs($this->lawyer())->post("/exec-flow/{$exec->number}/pay")->assertForbidden();
        $this->actingAs($this->client())->post("/exec-flow/{$exec->number}/action", ['action' => 'acceptOffer'])->assertForbidden();

        // الموظف بلا إجراءات
        $emp = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($emp)->post("/exec-flow/{$exec->number}/action", ['action' => 'close'])->assertForbidden();
    }

    public function test_out_of_order_transition_is_blocked(): void
    {
        $client = $this->client();
        $exec = $this->submit($client); // stage 2

        // لا يمكن الدفع قبل العرض/القبول (يُرفض بحارس المرحلة على مسار ميسّر)
        $this->actingAs($client)->post("/exec-flow/{$exec->number}/pay")
            ->assertSessionHasErrors('stage');
        $this->assertSame(2, $exec->refresh()->stage);
    }

    public function test_pay_requires_gateway_configured(): void
    {
        // بلا مفاتيح ميسّر (phpunit) → نقطة الدفع تردّ 503، ولا يُفتح الملف
        $client = $this->client();
        $exec = $this->reachOffer($client);
        $this->act($client, $exec, 'acceptOffer'); // stage 6 + فاتورة مستحقة

        $this->actingAs($client)->post("/exec-flow/{$exec->number}/pay")->assertStatus(503);

        $exec->refresh();
        $this->assertSame(6, $exec->stage);
        $this->assertFalse((bool) $exec->paid);
    }

    public function test_webhook_settles_exec_fee_and_opens_file(): void
    {
        // تأكيد الدفع عبر webhook ميسّر (يُعاد جلب الدفعة) → تسوية أتعاب التنفيذ وفتح الملف
        $this->configureMoyasar();
        $client = $this->client();
        $exec = $this->reachOffer($client);
        $this->act($client, $exec, 'acceptOffer');
        $invoice = Invoice::where('exec_id', $exec->id)->firstOrFail();
        $invoice->update(['gateway_ref' => 'inv_ex1']);

        Http::fake(['api.moyasar.com/v1/payments/*' => Http::response([
            'id' => 'pay_ex1', 'status' => 'paid', 'amount' => $invoice->amount * 100, 'currency' => 'SAR', 'invoice_id' => 'inv_ex1',
            'metadata' => ['invoice_number' => $invoice->number],
        ], 200)]);

        $this->postJson(route('webhooks.moyasar'), [
            'secret_token' => 'whsec_1', 'data' => ['id' => 'pay_ex1'],
        ])->assertOk();

        $exec->refresh();
        $this->assertTrue((bool) $exec->paid);
        $this->assertSame(7, $exec->stage); // السداد يقف عند «بانتظار الرفع في ناجز»
        $this->assertTrue((bool) $invoice->fresh()->paid);
    }

    public function test_client_render_endpoint_ok(): void
    {
        // التبويب الموحّد للعميل — يعرض صفحة التدفّق (المسار القديم /exec-preview يحوّل إليه)
        $this->actingAs($this->client())->get(route('execs'))->assertOk();
        $this->actingAs($this->client())->get('/exec-preview')->assertRedirect('/execs');
    }

    public function test_submit_dispatches_analysis_job(): void
    {
        // التحليل الذكيّ مطابور: التقديم يُرسِل المهمّة ويترك الطلب بمرحلة «تحليل ذكي»
        Queue::fake();
        $exec = $this->submit($this->client());

        Queue::assertPushed(AnalyzeExecutionJob::class);
        $this->assertSame(1, (int) $exec->stage);
        $this->assertFalse((bool) $exec->ai_done);
    }

    public function test_analysis_uses_ai_when_configured(): void
    {
        // مع مزوّد AI مهيّأ: الملخّص والإجراءات من الذكاء الاصطناعي (الطابور sync يُشغّل المهمّة فوراً)
        config(['services.glm.key' => 'test-key', 'services.glm.base' => 'https://api.z.ai/api/paas/v4']);
        Http::fake(['api.z.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'summary' => 'تحليل ذكيّ حقيقيّ: السند قابل للتنفيذ لدى محكمة التنفيذ.',
                'missing' => [],
                'procedures' => ['الحجز التنفيذيّ على الحسابات', 'الإفصاح عن الأصول'],
            ], JSON_UNESCAPED_UNICODE)]]],
        ], 200)]);

        // postSubmit لا submit: هذا الاختبار يهيّئ GLM بنفسه، وfakeAiSuccess كان سيدهس تهيئته
        $exec = $this->postSubmit($this->client())->refresh();

        $this->assertTrue((bool) $exec->ai_done);
        $this->assertSame('تحليل ذكيّ حقيقيّ: السند قابل للتنفيذ لدى محكمة التنفيذ.', $exec->ai_summary);
        $this->assertContains('الحجز التنفيذيّ على الحسابات', $exec->ai_procedures);
        $this->assertSame(2, (int) $exec->stage); // لا نواقص → قيد الدراسة
    }
}
