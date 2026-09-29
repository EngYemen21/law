<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\JourneyTransition;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **نافذة «إنشاء اجتماع جديد (Zoom)» في إدارة الاجتماعات** — ما ثبت بالاختبار (2026-09-28) وأُصلح:
 * موعدٌ مضى كان يُقبل، وموعدٌ فوق ارتباطٍ للمحامي المسؤول يُقبل، وفشل Zoom صامتٌ برسالة «أُنشئ بجلسة Zoom»،
 * والإنشاء خارج سجلّ الرحلة وبلا معاملة. والنقر المزدوج في الواجهة (حارسٌ نصّيّ أدناه).
 */
class MeetingCreateGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function create(array $over = [])
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'status' => 'active']);

        return $this->actingAs($admin)->post(route('admin.meetings.store'), $over + [
            'title' => 'اجتماع', 'type' => 'اجتماع داخلي', 'day' => now()->addDays(2)->toDateString(), 'time' => '10:00',
        ]);
    }

    public function test_a_past_time_is_refused(): void
    {
        $this->create(['day' => now()->subDay()->toDateString()])->assertSessionHasErrors(['time' => 'لا يمكن اختيار موعد ماضٍ — اختر وقتاً لاحقاً.']);
        $this->assertSame(0, Meeting::count());
    }

    public function test_the_responsible_lawyer_cannot_be_double_booked(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $at = now()->addDays(2)->setTime(10, 0);
        Appointment::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'lawyer_id' => $lawyer->id, 'ext_id' => 'AP-MG-1',
            'type' => 'استشارة حضورية', 'ico' => 'office', 'lawyer' => $lawyer->name, 'day' => $at->toDateString(), 'time' => '10:00',
            'starts_at' => $at, 'duration_min' => 60, 'place' => 'x', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);

        $this->create(['lawyer_id' => $lawyer->id])->assertSessionHasErrors(['time' => 'المحامي مشغول في هذا الوقت — اختر وقتاً آخر أو محامياً مختلفاً.']);
        $this->assertSame(0, Meeting::count());

        // وقتٌ حرّ للمحامي نفسه يُقبل
        $this->create(['lawyer_id' => $lawyer->id, 'time' => '12:00'])->assertSessionHasNoErrors();
        $this->assertSame(1, Meeting::count());
    }

    public function test_creation_is_journaled_with_its_client_invite_in_one_step(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->create(['client_id' => $client->id])->assertSessionHasNoErrors();

        $meeting = Meeting::firstOrFail();
        $this->assertSame('قادم', $meeting->status);
        $this->assertSame(1, MeetRequest::where('meeting_id', $meeting->id)->where('user_id', $client->id)->count());
        $this->assertSame(1, JourneyTransition::where('entity_type', 'Meeting')->where('entity_id', $meeting->id)->where('transition', 'meeting.create')->count());
    }

    /** Zoom غير مهيّأ في الاختبار: الاجتماع يُحفظ، والرسالة صادقة «بلا رابط Zoom»، ولا يدّعي رابطاً. */
    public function test_a_zoom_failure_is_reported_not_hidden(): void
    {
        $this->create()->assertSessionHas('flash', fn (string $m) => str_contains($m, 'بلا رابط Zoom'));

        $meeting = Meeting::firstOrFail();
        $this->assertNull($meeting->meet_link);
        $this->assertFalse((bool) $meeting->has_link);
    }

    public function test_the_modal_locks_submission_resets_and_reads_the_lawyers_busy_times(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/admin/meetmgmt.tsx'));
        $this->assertStringContainsString('disabled={action.busy}', $page, 'النقر المزدوج كان يُنشئ اجتماعين');
        $this->assertStringContainsString("void action.run('/admin/meetings'", $page);
        $this->assertStringContainsString('const resetForm = () => {', $page);
        $this->assertStringContainsString("useLawyerDaySlots('/admin', lawyerId, day)", $page);

        $ui = (string) file_get_contents(resource_path('js/lib/meeting-ui.tsx'));
        $this->assertSame(3, substr_count($ui, '= useLawyerDaySlots(base'), 'الدعوة وإعادة الإرسال وإعادة جدولة الاجتماع من المصدر نفسه');
        // شبكة الدوام من الإعدادات لا شبكةٌ ثابتة 09:00–20:30 (قرار المالك 2026-09-29)
        $this->assertStringNotContainsString('MI_SLOTS', $ui);
        $this->assertStringContainsString('return gridOn(day).map((time) => {', $ui);
        $this->assertStringNotContainsString('{ time: s, busy: isBusy }', $ui, 'منتقي إعادة الإرسال كان يقرأ busy لا taken');
    }
}
