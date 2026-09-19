<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Jobs\AnalyzeExecutionDocumentJob;
use App\Models\AiRun;
use App\Models\Execution;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewOutcome;
use App\Support\ExecService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * حرّاس ملفّ التنفيذ بعد مراجعة 2026-09-12: لا مخرجَ ذكيّاً للعميل قبل اعتماد محامٍ، ولا تسعيرَ
 * لطلبٍ مرفوض، ولا عرضَ بصفر، ولا استبدالَ لمستندٍ مقبول، ولا إسنادَ لمحامٍ فشل إجراؤه، ورسالةُ
 * الإجراء باسم فاعله، والإدارة تُشعَر بما تملك قراره.
 */
class ExecutionGuardsTest extends TestCase
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
        $user->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());

        return $user;
    }

    private function exec(User $client, array $attrs = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => $client->id,
            'number' => 'EXE-G-'.uniqid(),
            'subject' => 'تنفيذ حكم مالي',
            'sanad' => 'حكم قضائي',
            'amount' => 100000,
            'status' => 'قيد الدراسة',
            'tone' => 'b-blue',
            'stage' => 2,
        ], $attrs));
    }

    // ── ١) الدراسة الذكيّة لا تصل العميل قبل الاعتماد ──

    public function test_the_ai_study_stays_internal_until_a_lawyer_approves_it(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->exec($client, ['stage' => 1, 'status' => 'تحليل ذكي', 'assigned_lawyer_id' => $lawyer->id]);

        ExecService::applyAnalysis($exec, [
            'summary' => 'السند مستوفٍ بعد فحص المرفقات.',
            'missing' => [],
            'procedures' => ['قيد الطلب في ناجز'],
            'source' => AiSource::AiSuccess->value,
        ]);
        $exec->refresh()->load('messages', 'documents');

        $toClient = fn (Execution $e) => collect($e->toFlowCard(false, false)['messages'])->pluck('text')->implode(' ');
        $this->assertStringNotContainsString('السند مستوفٍ', $toClient($exec), 'المحادثة تحجب ما تحجبه البطاقة');
        $this->assertStringContainsString('السند مستوفٍ', collect($exec->toFlowCard(false, true)['messages'])->pluck('text')->implode(' '), 'والمكتب يراه ليراجعه');

        // ولا يُقال للعميل إنّ طلبه حُلّل بالذكاء الاصطناعي قبل أن يعتمده أحد
        $notice = (string) UserNotification::where('user_id', $client->id)->latest('id')->value('body');
        $this->assertStringNotContainsString('بالذكاء الاصطناعي', $notice);

        // والاعتماد ينشرها في محادثته
        AiReviewOutcome::apply(AiRun::latest('id')->firstOrFail(), AiReviewAction::Accept, $lawyer);
        $this->assertStringContainsString('السند مستوفٍ', $toClient($exec->fresh()->load('messages', 'documents')));
    }

    // ── ٢) المرفوض لا يُسعَّر ولا يُعرَض ──

    public function test_a_rejected_request_is_never_priced_or_offered(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = $this->staff(Role::Admin);
        $exec = $this->exec($client, ['stage' => 3, 'decision' => 'مرفوض']);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'setFee', 'fee' => 5000])->assertStatus(422);
        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'approveFee', 'fee' => 5000])->assertStatus(422);

        $exec->refresh();
        $this->assertSame(3, (int) $exec->stage, 'لا ينتقل إلى العرض');
        $this->assertFalse((bool) $exec->fee_approved);
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->where('body', 'like', '%عرض خدمة التنفيذ%')->count());
    }

    // ── ٣) لا عرضَ بصفر ──

    public function test_an_offer_cannot_be_approved_without_a_fee(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = $this->staff(Role::Admin);
        // الملفّ مسنَدٌ عمداً: `approveFee` صارت تحرس الإسناد أيضاً، فملفٌّ بلا محامٍ كان
        // سيُردّ 422 قبل أن يُقاس السعر — فيمرّ الاختبار على حارسٍ غير الذي يقيسه.
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->exec($client, [
            'stage' => 3, 'decision' => 'مقبول', 'fee' => 0,
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $this->actingAs($admin)->post(route('exec-flow.act', $exec), ['action' => 'approveFee'])->assertStatus(422);

        $exec->refresh();
        $this->assertFalse((bool) $exec->fee_approved);
        $this->assertSame(3, (int) $exec->stage);
    }

    // ── ٤) المستند المقبول لا يُستبدل، والمطلوب يُحلَّل ويظهر في المحادثة ──

    public function test_uploading_replaces_only_a_requested_or_returned_document(): void
    {
        Queue::fake();
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = $this->exec($client);
        $accepted = $exec->documents()->create(['label' => 'الهوية', 'status' => 'مقبول']);
        $requested = $exec->documents()->create(['label' => 'السند التنفيذي', 'status' => 'مطلوب']);
        $file = fn () => UploadedFile::fake()->create('sanad.pdf', 100, 'application/pdf');

        $this->actingAs($client)->post(route('exec-flow.documents.upload', [$exec, $accepted]), ['file' => $file()])->assertStatus(422);
        $this->assertSame('مقبول', $accepted->fresh()->status);
        $this->assertNull($accepted->fresh()->path);

        $this->actingAs($client)->post(route('exec-flow.documents.upload', [$exec, $requested]), ['file' => $file()])->assertRedirect();
        $this->assertSame('مرفوع', $requested->fresh()->status);
        Queue::assertPushed(AnalyzeExecutionDocumentJob::class);
        $this->assertStringContainsString('السند التنفيذي', collect($exec->fresh()->load('messages', 'documents')->toFlowCard(false, false)['messages'])->pluck('text')->implode(' '));
    }

    public function test_a_closed_file_refuses_uploads_and_messages_as_the_screen_shows(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        // صفٌّ قديم: بلا `stage` وحالته «مغلق» — كانت الشاشة تعرضه مفتوحاً والخادم يرفضه
        $exec = $this->exec($client, ['stage' => null, 'status' => 'مغلق']);
        $doc = $exec->documents()->create(['label' => 'الهوية', 'status' => 'مطلوب']);

        $this->assertTrue($exec->toFlowCard(false, false)['closed'], 'الشاشة تقرأ ما يقرؤه الخادم');
        $this->actingAs($client)->post(route('exec-flow.messages.store', $exec), ['body' => 'مرحبا'])->assertStatus(422);
        $this->actingAs($client)->post(route('exec-flow.documents.upload', [$exec, $doc]), [
            'file' => UploadedFile::fake()->create('id.pdf', 50, 'application/pdf'),
        ])->assertStatus(422);
    }

    // ── ٥) إجراءٌ فاشل لا يُسنِد الملفّ لصاحبه ──

    public function test_a_failed_pickup_leaves_the_file_unassigned(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->exec($client, ['stage' => 1, 'status' => 'تحليل ذكي']); // القبول يلزمه المرحلة 2

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'accept'])->assertSessionHasErrors('stage');

        $this->assertNull($exec->fresh()->assigned_lawyer_id, 'لا يُختم الملفّ باسم من رُفض إجراؤه');
    }

    public function test_a_successful_pickup_assigns_the_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->exec($client);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'accept'])->assertRedirect();

        $this->assertSame($lawyer->id, $exec->fresh()->assigned_lawyer_id);
        $this->assertSame(3, (int) $exec->fresh()->stage);
    }

    // ── ٦) الرسالة باسم فاعلها ──

    public function test_each_action_is_written_in_the_name_of_who_did_it(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = $this->staff(Role::Employee);
        $admin = $this->staff(Role::Admin);
        $lawyer = $this->staff(Role::Lawyer);

        $intake = $this->exec($client);
        $this->actingAs($employee)->post(route('exec-flow.act', $intake), ['action' => 'requestDocs'])->assertRedirect();
        $msg = $intake->messages()->where('role', 'نواقص')->firstOrFail();
        $this->assertSame('staff', $msg->who, 'طلب الموظّف لا يُنسب للمحامي');
        $this->assertSame($employee->name, $msg->name);

        $open = $this->exec($client, ['stage' => 8, 'status' => 'قيد التنفيذ', 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);
        $this->actingAs($lawyer)->post(route('exec-flow.act', $open), ['action' => 'close'])->assertRedirect();
        $closing = $open->messages()->where('role', 'إغلاق')->firstOrFail();
        $this->assertSame('lawyer', $closing->who, 'إغلاق المحامي لا يُنسب للإدارة العليا');
        $this->assertSame($lawyer->name, $closing->name);

        // وإغلاق الإدارة يبقى باسمها
        $other = $this->exec($client, ['stage' => 8, 'status' => 'قيد التنفيذ']);
        $this->actingAs($admin)->post(route('exec-flow.act', $other), ['action' => 'close'])->assertRedirect();
        $this->assertSame('admin', $other->messages()->where('role', 'إغلاق')->value('who'));
    }

    // ── ٧) الإدارة تُشعَر بما تملك قراره، والمكتب بما يفعله العميل ──

    public function test_the_office_is_told_what_it_must_act_on(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->staff(Role::Lawyer);
        $admin = $this->staff(Role::Admin);

        // أتعاب المحامي تنتظر اعتماد الإدارة — وكانت تنتظر بلا علمها
        $pricing = $this->exec($client, ['stage' => 3, 'decision' => 'مقبول', 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);
        $this->actingAs($lawyer)->post(route('exec-flow.act', $pricing), ['action' => 'saveFee', 'fee' => 6000, 'duration' => '30 يوم', 'payMethod' => 'دفعة واحدة'])->assertRedirect();
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('body', 'like', '%بانتظار اعتماد الإدارة%')->exists());

        // وقبول العميل للعرض يصل المكتب
        $offer = $this->exec($client, ['stage' => 5, 'fee' => 6000, 'vat' => 900, 'fee_approved' => true, 'assigned_lawyer_id' => $lawyer->id]);
        $this->actingAs($client)->post(route('exec-flow.act', $offer), ['action' => 'acceptOffer'])->assertRedirect();
        $this->assertTrue(UserNotification::where('user_id', $lawyer->id)->where('body', 'like', '%قبل العميل عرض التنفيذ%')->exists());
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('body', 'like', '%قبل العميل عرض التنفيذ%')->exists());
    }

    /** الاستفسار والرفض يصلان الإدارة لأنها وحدها تعيد التسعير. */
    public function test_offer_pushback_reaches_the_admin_who_can_reprice(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = $this->staff(Role::Admin);
        $exec = $this->exec($client, ['stage' => 5, 'fee' => 6000, 'fee_approved' => true]);

        $this->actingAs($client)->post(route('exec-flow.act', $exec), ['action' => 'rejectOffer'])->assertRedirect();

        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('body', 'like', '%رفض العميل عرض خدمة التنفيذ%')->exists());
    }

    // ── ٨) المرجع المعروض للعميل لا يكون فارغاً ──

    public function test_updates_on_a_file_without_an_internal_number_still_read_well(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = $this->staff(Role::Lawyer);
        $exec = $this->exec($client, ['stage' => 8, 'status' => 'قيد التنفيذ', 'exec_no' => null, 'assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($lawyer)->post(route('exec-flow.act', $exec), ['action' => 'addProcedure', 'title' => 'حجز حساب بنكي'])->assertRedirect();

        $body = (string) UserNotification::where('user_id', $client->id)->latest('id')->value('body');
        $this->assertStringContainsString($exec->number, $body, 'يُذكر رقم الطلب بدل مرجعٍ فارغ');
        $this->assertStringNotContainsString('الداخليّ )', $body);
    }
}
