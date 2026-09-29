<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **سقف تغيير الموعد** (قرار المالك 2026-09-29): طلب العميل تغيير موعد الاجتماع لا يتكرّر وهو قيد المراجعة
 * (كان كلّ ضغطٍ إشعاراً جديداً للمحامي والإدارة)، ولا يُعاد جدولة الاجتماع ولا الاستشارة بعد السقف (افتراضاً
 * مرّتين، من «إعدادات النظام») إلا بيد الإدارة العليا.
 */
class MeetingRescheduleLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Http::fake();
        Mail::fake();
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
    }

    private function meeting(): Meeting
    {
        return Meeting::create([
            'user_id' => $this->client->id, 'ref' => 'M-LIM-'.uniqid(), 'title' => 'اجتماع متابعة', 'when_label' => 'غداً',
            'starts_at' => now()->addDay(), 'status' => MeetingStatus::Upcoming->value, 'assigned_lawyer_id' => $this->lawyer->id,
            'created_by' => 'الموظف',
        ]);
    }

    private function reschedule(User $by, Meeting $meeting)
    {
        $prefix = $by->role->value;

        return $this->actingAs($by)->post(route("{$prefix}.meetings.reschedule", $meeting), [
            'day' => now()->addWeek()->toDateString(), 'time' => '11:00', 'reason' => 'client_request',
        ]);
    }

    public function test_client_change_request_is_not_repeated_while_pending(): void
    {
        $meeting = $this->meeting();

        $this->actingAs($this->client)->post(route('meetings.change-request', $meeting))->assertRedirect();
        $this->actingAs($this->client)->post(route('meetings.change-request', $meeting))->assertStatus(422);

        $this->assertSame(1, UserNotification::where('user_id', $this->lawyer->id)->count(), 'إشعارٌ واحد لا اثنان');
        $this->assertNotNull($meeting->fresh()->reschedule_requested_at);

        $this->actingAs($this->client)->get(route('meetings'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('meetings.0.canRequestChange', false)
            ->where('meetings.0.changeRequestNote', 'طلبك السابق قيد المراجعة — سيتواصل معك المكتب.'));
    }

    public function test_staff_reschedule_answers_the_request_and_counts_until_the_limit(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['إرسال دعوات الاجتماعات'])->get());
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->meeting();

        $this->actingAs($this->client)->post(route('meetings.change-request', $meeting))->assertRedirect();
        $this->reschedule($employee, $meeting)->assertRedirect();
        $this->assertNull($meeting->fresh()->reschedule_requested_at, 'أُجيب الطلب');
        $this->assertSame(1, $meeting->fresh()->reschedule_count);

        $this->reschedule($employee, $meeting->fresh())->assertRedirect();
        $this->assertSame(2, $meeting->fresh()->reschedule_count);

        // بلغ السقف (2): العميل يُردّ بسببه، والموظّف يُردّ، والإدارة العليا تعيد الجدولة
        $this->actingAs($this->client)->post(route('meetings.change-request', $meeting->fresh()))->assertStatus(422);
        $this->reschedule($employee, $meeting->fresh())->assertStatus(422);
        $this->reschedule($admin, $meeting->fresh())->assertRedirect();
        $this->assertSame(3, $meeting->fresh()->reschedule_count);

        // والسقف من الإعدادات: رفعه يعيد الإتاحة
        Setting::put('meeting_reschedule_limit', '5');
        $this->assertNull($meeting->fresh()->changeRequestBlocker());
    }

    public function test_consult_client_request_is_refused_at_the_limit(): void
    {
        $consult = Consult::create([
            'user_id' => $this->client->id, 'ref' => 'CN-LIM-1', 'subject' => 'استشارة', 'channel' => 'مرئية', 'lawyer' => '—',
            'status' => ConsultStatus::ReadyForLawyer->value, 'starts_at' => now()->addDays(3),
        ]);
        // العدّاد خارج `$fillable` — يكتبه انتقال إعادة الجدولة وحده
        $consult->forceFill(['reschedule_count' => 2])->save();

        $this->actingAs($this->client)->post(route('consults.reschedule-request', $consult))
            ->assertStatus(422);
        $this->assertSame('بلغت الاستشارة الحدّ الأقصى لتغيير الموعد — تواصل مع المكتب مباشرةً.', $consult->fresh()->rescheduleRequestBlocker());
    }
}
