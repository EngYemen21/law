<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\ExecutionEventMail;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\ExecFlow;
use App\Support\ExecService;
use App\Support\ExecutionCreation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * **بريد التنفيذ لا يقتصر على العميل** (قرار المالك 2026-09-12).
 *
 * كان كلّ بريد التنفيذ يذهب لصاحب الطلب وحده: فلا يعلم المكتب بطلبٍ جديد، ولا الإدارة بأتعابٍ
 * تنتظر اعتمادها، ولا المحامي بانقضاء مهلة الوفاء — تُقرأ هذه كلّها بدخول اللوحة لا قبله.
 * ولكلّ فئةٍ رابطُ لوحتها في الرسالة، فلا يُرسَل للموظّف رابطٌ يردّه حارس الصلاحية.
 */
class ExecEmailsTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client, 'email' => 'client'.uniqid().'@test.sa']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'email' => 'admin'.uniqid().'@test.sa']);
    }

    private function exec(User $client, array $attrs = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-M-'.uniqid(),
            'subject' => 'تنفيذ حكم مالي',
            'sanad' => 'حكم قضائي',
            'amount' => 100000,
            'stage' => 3,
            'status' => ExecFlow::label(3),
            'tone' => ExecFlow::tone(3),
        ], $attrs));
    }

    private function sentTo(string $event, User $user): bool
    {
        return Mail::queued(ExecutionEventMail::class, fn ($m) => $m->event === $event && $m->hasTo($user->email))->isNotEmpty();
    }

    public function test_a_new_request_confirms_to_the_client_and_reaches_the_office(): void
    {
        Mail::fake();
        Queue::fake();
        $client = $this->client();
        $admin = $this->admin();
        $employee = User::factory()->create(['role' => Role::Employee, 'email' => 'emp'.uniqid().'@test.sa']);

        $exec = ExecService::submit($client, ['sanad' => 'شيك', 'subject' => 'تحصيل قيمة شيك مرتجع']);

        $this->assertTrue($this->sentTo('submitted', $client), 'تأكيد الاستلام لصاحب الطلب');
        $this->assertTrue($this->sentTo('newRequest', $admin), 'الإدارة تُخطَر بالطلب الجديد');
        $this->assertTrue($this->sentTo('newRequest', $employee), 'والموظّف كذلك');
        $this->assertNotNull($exec->number);

        // ولكلّ فئةٍ رابط لوحتها — لا رابط إدارة في بريد موظّف
        $adminMail = Mail::queued(ExecutionEventMail::class, fn ($m) => $m->event === 'newRequest' && $m->hasTo($admin->email))->first();
        $empMail = Mail::queued(ExecutionEventMail::class, fn ($m) => $m->event === 'newRequest' && $m->hasTo($employee->email))->first();
        $this->assertStringEndsWith('/admin/execs', $adminMail->panelUrl());
        $this->assertStringEndsWith('/employee/execs', $empMail->panelUrl());
    }

    public function test_fees_awaiting_approval_reach_the_admin(): void
    {
        Mail::fake();
        $client = $this->client();
        $admin = $this->admin();
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->exec($client, ['decision' => 'مقبول', 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);

        ExecService::saveFee($exec, 6000, '30 يوم', 'fixed', null, $lawyer);

        $this->assertTrue($this->sentTo('feeAwaitingApproval', $admin));
        $this->assertTrue(Mail::queued(ExecutionEventMail::class, fn ($m) => $m->hasTo($client->email))->isEmpty(), 'العميل لا يُبلَّغ بأتعابٍ لم تُعتمد');
    }

    public function test_offer_pushback_reaches_the_admin_who_reprices(): void
    {
        Mail::fake();
        $client = $this->client();
        $admin = $this->admin();
        $offer = ['stage' => 5, 'status' => ExecFlow::label(5), 'fee' => 6000, 'vat' => 900, 'fee_approved' => true];

        ExecService::inquire($this->exec($client, $offer));
        ExecService::rejectOffer($this->exec($client, $offer));

        $this->assertTrue($this->sentTo('offerInquiry', $admin));
        $this->assertTrue($this->sentTo('offerRejected', $admin));
    }

    public function test_the_client_is_told_when_the_request_is_refused(): void
    {
        Mail::fake();
        $client = $this->client();
        $exec = $this->exec($client, ['stage' => 2, 'status' => ExecFlow::label(2)]);

        ExecService::reject($exec);

        $this->assertTrue($this->sentTo('rejected', $client));
    }

    public function test_each_collection_is_mailed_to_the_client_with_the_remainder(): void
    {
        Mail::fake();
        $client = $this->client();
        $exec = $this->exec($client, [
            'stage' => 8, 'status' => ExecFlow::label(8), 'paid' => true,
            'najiz_request_no' => 'NJ-1', 'registered_at' => now()->subDay()->toDateString(),
        ]);

        ExecService::addCollection($exec, 25000, 'حجز حساب بنكيّ');

        $mailed = Mail::queued(ExecutionEventMail::class, fn ($m) => $m->event === 'collection' && $m->hasTo($client->email))->first();
        $this->assertNotNull($mailed);
        $this->assertSame(25000, (int) $mailed->execution->collected, 'الرسالة تحمل المحصَّل بعد التحديث لا قبله');
    }

    public function test_opening_an_execution_from_a_case_mails_the_assigned_lawyer(): void
    {
        Mail::fake();
        $client = $this->client();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'email' => 'lawyer'.uniqid().'@test.sa']);
        $admin = $this->admin();
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-7777', 'type' => 'نزاع تجاري',
            'status' => 'صدر الحكم', 'tone' => 'b-cyan', 'ruling' => 'إلزام المدّعى عليه بالمبلغ.',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        ExecutionCreation::fromCase($case, $admin);

        $this->assertTrue($this->sentTo('assigned', $lawyer), 'المحامي يُبلَّغ بما أُسند إليه');
    }

    /** الملتقِط لنفسه لا يُراسَل بإسنادٍ فعله للتوّ. */
    public function test_a_lawyer_who_opens_it_himself_is_not_mailed_an_assignment(): void
    {
        Mail::fake();
        $client = $this->client();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'email' => 'self'.uniqid().'@test.sa']);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-7778', 'type' => 'نزاع تجاري',
            'status' => 'صدر الحكم', 'tone' => 'b-cyan', 'ruling' => 'حكم.',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        ExecutionCreation::fromCase($case, $lawyer);

        $this->assertFalse($this->sentTo('assigned', $lawyer));
    }

    public function test_an_expired_pay_deadline_alerts_the_office_once(): void
    {
        Mail::fake();
        $client = $this->client();
        $admin = $this->admin();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'email' => 'l'.uniqid().'@test.sa']);
        $exec = $this->exec($client, [
            'stage' => 8, 'status' => ExecFlow::label(8), 'paid' => true,
            'najiz_request_no' => 'NJ-9', 'registered_at' => now()->subDays(10)->toDateString(),
            'notified_at' => now()->subDays(9)->toDateString(), 'pay_due_at' => now()->subDays(4)->toDateString(),
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $this->artisan('exec:send-paydue-alerts')->assertSuccessful();

        $this->assertTrue($this->sentTo('payDueOverdue', $lawyer));
        $this->assertTrue($this->sentTo('payDueOverdue', $admin));
        $this->assertNotNull($exec->fresh()->pay_due_alert_sent_at);

        // تكرار التشغيل لا يكرّر البريد
        $before = Mail::queued(ExecutionEventMail::class, fn ($m) => $m->event === 'payDueOverdue')->count();
        $this->artisan('exec:send-paydue-alerts')->assertSuccessful();
        $this->assertSame($before, Mail::queued(ExecutionEventMail::class, fn ($m) => $m->event === 'payDueOverdue')->count());
    }

    /** إجراءات عدم الوفاء مسجّلة ⇒ المهلة عولجت، فلا تنبيه. */
    public function test_no_alert_when_measures_were_already_recorded(): void
    {
        Mail::fake();
        $client = $this->client();
        $this->admin();
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->exec($client, [
            'stage' => 8, 'status' => ExecFlow::label(8), 'paid' => true,
            'pay_due_at' => now()->subDays(3)->toDateString(), 'measures' => ['منع السفر'],
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $this->artisan('exec:send-paydue-alerts')->assertSuccessful();

        Mail::assertNotQueued(ExecutionEventMail::class);
    }
}
