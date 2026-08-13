<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * تحليلات تقارير الاجتماعات للإدارة العليا — كلها من بيانات حقيقية (duration_sec + مهام القرارات).
 */
class MeetingReportsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_card_exposes_real_zoom_session_fields(): void
    {
        $meeting = Meeting::create([
            'ref' => 'M-Z1', 'title' => 'اجتماع', 'when_label' => 'اليوم', 'status' => 'منتهٍ',
            'attend' => 80, 'duration_sec' => 1800,
            'join_time' => now()->setTime(10, 0), 'leave_time' => now()->setTime(10, 30),
            'starts_at' => now(), 'created_by' => 'الإدارة',
        ]);

        $card = $meeting->toFullCard();
        $this->assertSame(1800, $card['durationSec']);
        $this->assertNotNull($card['joinTime']);
        $this->assertNotNull($card['leaveTime']);
        $this->assertNotNull($card['startsAt']);
        $this->assertSame('الإدارة', $card['createdBy']);
    }

    public function test_reports_returns_real_analytics(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أحمد']);

        Meeting::create([
            'ref' => 'M-A1', 'title' => 'اجتماع منتهٍ', 'when_label' => 'اليوم', 'status' => 'منتهٍ',
            'attend' => 90, 'duration_sec' => 1800, 'assigned_lawyer_id' => $lawyer->id, 'branch' => $lawyer->branch,
            'starts_at' => now(),
        ]);
        // مهمتان من قرارات هذا الاجتماع: واحدة منجزة → معدل تنفيذ 50%
        Task::create(['assigned_to' => $lawyer->id, 'title' => 'مهمة1', 'ref' => 'M-A1', 'status' => 'منجزة']);
        Task::create(['assigned_to' => $lawyer->id, 'title' => 'مهمة2', 'ref' => 'M-A1', 'status' => 'مفتوحة']);

        $this->actingAs($admin)->get(route('admin.meetreports'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('admin/meetreports')
                ->where('analytics.avgActualMinutes', 30)
                ->where('analytics.decisionRate', 50)
                ->where('analytics.tasksFromDecisions', 2)
                ->has('analytics.monthlyTrend', 6)
                ->has('analytics.attendanceByLawyer', 1)
                ->where('analytics.attendanceByLawyer.0.0', 'أحمد')
                ->where('analytics.attendanceByLawyer.0.1', 30)
            );
    }
}
