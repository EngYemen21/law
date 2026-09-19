<?php

namespace Tests\Feature;

use App\Console\Commands\RetryExecutionStudies;
use App\Enums\Role;
use App\Jobs\AnalyzeExecutionJob;
use App\Models\Execution;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LegalAiService;
use App\Support\ExecService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **الطلب المرفوض: بابٌ مغلق ومخرجٌ واحد.**
 *
 * `reject` يكتب `decision = 'مرفوض'` **ولا ينقل المرحلة** — قرارٌ مقصود وموثَّق (المرفوض ليس
 * مؤرشفاً)، لكنّه ترك الملفّ في المرحلة 2 أو 3: فكلّ إجراءٍ محروسٍ بهما كان يعمل عليه، وكلّ
 * ما يستثني `isClosed()` وحده كان يلتقطه. فالإحالة تمحو «رُفض الطلب بعد الدراسة»، وطلبُ
 * المستندات يطالب عميلاً وصله بريدُ الرفض، والإسنادُ يُراسل محامياً على ملفٍّ لا عمل فيه،
 * والطابور يعيد دراسته فيصله «قيد الدراسة» بعد أن أُبلغ بالرفض.
 *
 * والمخرج (قرار المالك 2026-09-13): يبقى مفتوحاً ولا يُغلق تلقائيّاً — **زرُّ إنهاءٍ للإدارة
 * وحدها** على المرحلتين 2 و3.
 */
class ExecRejectedFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function staff(Role $role): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $user->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب', 'إجراءات المحكمة والجلسات'])->get());

        return $user;
    }

    private function rejected(array $attrs = []): Execution
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-REJ-'.uniqid(),
            'subject' => 'تنفيذ حكم مالي',
            'sanad' => 'حكم قضائي',
            'amount' => 100000,
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
            'stage' => 2,
            'decision' => 'مرفوض',
            'last_action' => 'رُفض الطلب بعد الدراسة',
        ], $attrs));
    }

    // ── ب‑٢ (أ) أربعة أبواب كانت مفتوحة على المرفوض ──

    /** الإحالة كانت تمحو سطر الرفض وتُرجع الملفّ إلى «قيد الدراسة» وتُنبّه الإدارة به. */
    public function test_a_rejected_request_cannot_be_referred_again(): void
    {
        $exec = $this->rejected(['stage' => 1, 'status' => 'تحليل ذكي']);
        $admin = $this->staff(Role::Admin);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'refer'])->assertStatus(422);

        $exec->refresh();
        $this->assertSame(1, (int) $exec->stage, 'أُحيل ملفٌّ أُغلق قراره');
        $this->assertSame('رُفض الطلب بعد الدراسة', $exec->last_action, 'مُحي سطر الرفض');
        $this->assertFalse(
            UserNotification::where('user_id', $admin->id)->where('body', 'like', '%بانتظار إسناد الإدارة%')->exists(),
            'نُبّهت الإدارة بملفٍّ لا إجراء فيه'
        );
    }

    /** وطلبُ المستندات كان يطالب عميلاً وصله بريدُ الرفض بنواقصَ لن تُستكمل. */
    public function test_a_rejected_request_cannot_be_asked_for_documents(): void
    {
        $exec = $this->rejected();
        $employee = $this->staff(Role::Employee);

        $this->actingAs($employee)->post(route('exec-flow.act', $exec), ['action' => 'requestDocs'])->assertStatus(422);

        $this->assertSame(0, $exec->documents()->count(), 'أُنشئت مستنداتٌ «مطلوبة» على ملفٍّ مرفوض');
        $this->assertFalse(
            UserNotification::where('user_id', $exec->user_id)->where('body', 'like', '%مستندات إضافية%')->exists()
        );
    }

    /** والإسنادُ كان يُحمّل محامياً ملفّاً لا خطوة فيه، ويصله إشعارٌ وبريدُ «أُسند إليك». */
    public function test_a_rejected_request_cannot_be_assigned_to_a_lawyer(): void
    {
        $exec = $this->rejected();
        $admin = $this->staff(Role::Admin);
        $lawyer = $this->staff(Role::Lawyer);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'assignLawyer', 'lawyer_id' => $lawyer->id,
        ])->assertStatus(422);

        $this->assertNull($exec->fresh()->assigned_lawyer_id);
        $this->assertFalse(
            UserNotification::where('user_id', $lawyer->id)->where('body', 'like', '%أُسند إليك%')->exists()
        );
    }

    /**
     * **ومسار الطابور صامتٌ لا يرمي**: مهمّة الدراسة كانت تستثني `isClosed()` وحده — والمرفوض
     * ليس مغلقاً — فتُعاد دراسته ويصل العميلَ «قيد الدراسة» بعد أن أُبلغ بالرفض.
     */
    public function test_the_study_queue_skips_a_rejected_request_silently(): void
    {
        $exec = $this->rejected(['ai_done' => false]);
        $before = UserNotification::where('user_id', $exec->user_id)->count();

        (new AnalyzeExecutionJob($exec))->handle(app(LegalAiService::class));

        $exec->refresh();
        $this->assertSame(0, (int) $exec->ai_attempts, 'استُهلكت محاولة دراسةٍ على ملفٍّ مرفوض');
        $this->assertNull($exec->ai_summary);
        $this->assertSame($before, UserNotification::where('user_id', $exec->user_id)->count(), 'وصل العميلَ إشعارٌ بعد بريد الرفض');
    }

    /** والأمر المجدول شبكةُ أمانٍ للطابور — فيستثنيه مثله، وإلّا أعاده كلّ ساعة. */
    public function test_the_scheduled_retry_never_reschedules_a_rejected_request(): void
    {
        Queue::fake();

        $rejected = $this->rejected(['ai_done' => false]);
        $pending = $this->rejected(['ai_done' => false, 'decision' => null, 'number' => 'EXE-PEND-'.uniqid()]);

        $this->artisan(RetryExecutionStudies::class)->assertSuccessful();

        Queue::assertPushed(AnalyzeExecutionJob::class, 1);
        Queue::assertPushed(
            AnalyzeExecutionJob::class,
            fn (AnalyzeExecutionJob $job) => (int) $job->execution->id === (int) $pending->id
        );
        Queue::assertNotPushed(
            AnalyzeExecutionJob::class,
            fn (AnalyzeExecutionJob $job) => (int) $job->execution->id === (int) $rejected->id
        );
    }

    // ── ب‑٢ (ب) المخرج: زرُّ إنهاءٍ للإدارة وحدها ──

    /** الإدارة تُنهي الملفّ المرفوض وتؤرشفه — المرحلة 9 وسببٌ مسجَّل. */
    public function test_an_admin_can_archive_a_rejected_file(): void
    {
        $exec = $this->rejected(['stage' => 3]);
        $admin = $this->staff(Role::Admin);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), [
            'action' => 'close', 'reason' => 'أخرى',
        ])->assertRedirect();

        $exec->refresh();
        $this->assertSame(9, (int) $exec->stage);
        $this->assertTrue($exec->isClosed());
        $this->assertSame('أخرى', $exec->closed_reason);
    }

    /** والمحامي لا يؤرشفه: قرارُ توزيعٍ وأرشفةٍ إداريّ، والحارس يردّه 403 لا 422. */
    public function test_a_lawyer_cannot_archive_a_rejected_file(): void
    {
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->rejected(['assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'close'])->assertForbidden();

        $this->assertSame(2, (int) $exec->fresh()->stage);
    }

    /** والباب لا يتّسع لغير المرفوض: ملفٌّ في المرحلة 2 قراره لم يُحسم لا يُغلق. */
    public function test_the_new_door_does_not_open_for_a_file_that_was_never_rejected(): void
    {
        $exec = $this->rejected(['decision' => null, 'last_action' => 'أُحيل الطلب لقسم التنفيذ']);
        $admin = $this->staff(Role::Admin);

        // حارس المرحلة يرمي `ValidationException` (رسالة على الحقل `stage`) لا 422
        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'close'])
            ->assertSessionHasErrors('stage');

        $this->assertSame(2, (int) $exec->fresh()->stage);
    }

    /** وإنهاء الملفّ العامل (7‑8) يبقى كما كان — للمحامي المسنَد كما للإدارة. */
    public function test_closing_a_working_file_is_untouched(): void
    {
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->rejected([
            'stage' => 8, 'decision' => 'مقبول', 'status' => 'قيد التنفيذ',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), [
            'action' => 'close', 'reason' => 'سداد كامل',
        ])->assertRedirect();

        $this->assertSame(9, (int) $exec->fresh()->stage);
        $this->assertSame('سداد كامل', $exec->fresh()->closed_reason);
    }

    /** والمرفوض الذي أُرشف لا يُغلق مرّتين — مرحلته 9 لم تعد في [2,3] ولا في [7,8]. */
    public function test_an_archived_rejected_file_cannot_be_closed_again(): void
    {
        $exec = $this->rejected();
        $admin = $this->staff(Role::Admin);
        ExecService::close($exec, 'أخرى', $admin);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec->fresh()), ['action' => 'close'])
            ->assertSessionHasErrors('stage');
    }
}
