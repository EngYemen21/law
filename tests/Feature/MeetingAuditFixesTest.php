<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Services\AdminDashboardService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **إصلاحات تدقيق الاجتماعات** (قرار المالك 2026-10-01) — كلٌّ منها رُصد حيّاً في المتصفّح:
 *
 * ١. اعتماد المحضر بلا قيد تدقيق — ونظيره اعتماد الدعوة يُسجَّل.
 * ٢. «عميل» الاجتماع يقبل أيّ حساب — فيُدعى محامٍ دعوةَ عميل.
 * ٣. عدّادات «بانتظار الاعتماد» تعدّ منتهياً بلا مخرجات لا يُعتمد (`Meeting::canApprove` هي القاعدة).
 * ٤. استعلامٌ لكلّ صفّ (N+1) في قوائم الاجتماعات والدعوات.
 */
class MeetingAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'status' => 'active']);
    }

    private static int $seq = 0;

    private function meeting(array $attrs = []): Meeting
    {
        self::$seq++;

        return Meeting::create($attrs + [
            'ref' => 'M-AUD-'.self::$seq, 'title' => 'اجتماع '.self::$seq, 'when_label' => 'أمس · 10:00',
            'status' => MeetingStatus::Ended->value,
        ]);
    }

    // ————— ١. اعتماد المحضر يُسجَّل في التدقيق —————

    public function test_approving_meeting_minutes_writes_an_audit_row(): void
    {
        $admin = $this->admin();
        $meeting = $this->meeting(['summary' => 'ملخّص حقيقي للجلسة.']);

        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertRedirect();

        $log = AuditLog::where('action', 'اعتماد محضر اجتماع')->first();
        $this->assertNotNull($log, 'اعتماد المحضر بلا قيد تدقيق');
        $this->assertSame('اجتماعات', $log->category);
        $this->assertSame($meeting->getMorphClass(), $log->auditable_type);
        $this->assertSame($meeting->id, (int) $log->auditable_id);
        $this->assertSame($admin->id, (int) $log->user_id);
        $this->assertStringContainsString($admin->name, $log->description);
        $this->assertStringContainsString($meeting->title, $log->description);
        $this->assertStringContainsString($meeting->ref, $log->description);
    }

    // ————— ٢. عميل الاجتماع حساب عميل حصراً —————

    public function test_meeting_client_must_be_a_client_account(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $count = Meeting::count();

        $this->actingAs($this->admin())->post(route('admin.meetings.store'), [
            'title' => 'اجتماع', 'type' => 'اجتماع عميل', 'day' => now()->addDays(2)->toDateString(), 'time' => '10:00',
            'client_id' => $lawyer->id,
        ])->assertSessionHasErrors('client_id');

        $this->assertSame($count, Meeting::count(), 'لا يُنشأ اجتماعٌ «عميلُه» محامٍ');
    }

    public function test_meeting_with_a_real_client_is_still_accepted(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($this->admin())->post(route('admin.meetings.store'), [
            'title' => 'اجتماع', 'type' => 'اجتماع عميل', 'day' => now()->addDays(2)->toDateString(), 'time' => '10:00',
            'client_id' => $client->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($client->id, (int) Meeting::latest('id')->firstOrFail()->user_id);
    }

    // ————— ٣. «بانتظار الاعتماد» = ما يُعتمد فعلاً —————

    public function test_dashboard_radar_counts_only_meetings_that_can_be_approved(): void
    {
        $this->meeting(['summary' => 'ملخّص حقيقي.']);   // يُعتمد
        $this->meeting();                                  // منتهٍ بلا مخرجات — لا يُعتمد
        $this->meeting(['summary' => 'ملخّص.', 'approve' => 'معتمد', 'sum_approved' => true]); // معتمد
        $this->meeting(['status' => MeetingStatus::Upcoming->value, 'summary' => 'مبكر']); // لم ينته

        $item = collect(app(AdminDashboardService::class)->get360Data(true)['radar'])->firstWhere('id', 'pending-meetings');

        $this->assertNotNull($item);
        $this->assertSame(1, $item['count']);
        $this->assertSame(1, Meeting::awaitingApprovalCount());
    }

    public function test_dashboard_radar_hides_card_when_nothing_is_approvable(): void
    {
        $this->meeting(); // منتهٍ بلا مخرجات

        $item = collect(app(AdminDashboardService::class)->get360Data(true)['radar'])->firstWhere('id', 'pending-meetings');

        $this->assertNull($item, 'بطاقة رادار لمحضرٍ لا زرّ اعتمادٍ له');
    }

    // ————— ٤. لا استعلام لكلّ صفّ —————

    private function seedMeetings(int $n): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $staff = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        for ($i = 0; $i < $n; $i++) {
            $client = User::factory()->create(['role' => Role::Client]);
            $m = $this->meeting(['user_id' => $client->id, 'client_name' => $client->name, 'assigned_lawyer_id' => $lawyer->id, 'summary' => 'ملخّص']);
            $m->participantUsers()->attach($staff->id);
        }
    }

    private function seedRequests(int $n): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        for ($i = 0; $i < $n; $i++) {
            $client = User::factory()->create(['role' => Role::Client]);
            $m = $this->meeting(['user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id, 'status' => MeetingStatus::Upcoming->value, 'starts_at' => now()->addDay()]);
            MeetRequest::create([
                'user_id' => $client->id, 'meeting_id' => $m->id, 'ref' => 'MR-AUD-'.self::$seq,
                'service' => 'استشارة', 'type' => 'استشارة مرئية', 'day' => now()->addDay()->toDateString(), 'time' => '10:00',
                'assigned_lawyer_id' => $lawyer->id, 'sent_by' => 'الإدارة', 'stage' => MeetRequest::STAGE_CONFIRMED,
            ]);
        }
    }

    private function queriesFor(User $admin, string $route): int
    {
        // طلبٌ تمهيديّ يُدفئ ما يُخزَّن مؤقّتاً (الإعدادات…) فلا يُحسب فرقاً
        $this->actingAs($admin)->get(route($route))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route($route))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function listRoutes(): array
    {
        return [
            'إدارة الاجتماعات' => ['admin.meetmgmt', 'meetings'],
            'اعتماد الاجتماعات' => ['admin.meetings', 'meetings'],
            'دعوات الاجتماعات' => ['admin.meetreqs', 'requests'],
        ];
    }

    #[DataProvider('listRoutes')]
    public function test_list_query_count_does_not_grow_with_rows(string $route, string $kind): void
    {
        $admin = $this->admin();
        $seed = fn (int $n) => $kind === 'requests' ? $this->seedRequests($n) : $this->seedMeetings($n);

        $seed(2);
        $few = $this->queriesFor($admin, $route);
        $seed(6);
        $many = $this->queriesFor($admin, $route);

        $this->assertSame($few, $many, "عدد الاستعلامات ينمو بعدد الصفوف ({$few} ← {$many}) — استعلامٌ لكلّ صفّ");
    }
}
