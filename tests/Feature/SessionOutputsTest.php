<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\MeetingStatusBroadcast;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * مخرجات الجلسة الحقيقية: توليد ملخص/محضر/قرارات عند الإنهاء + تحويلها لمهام حقيقية
 * + تسليم العميل بعد الاعتماد + بثّ لحظي — للاجتماعات والاستشارات.
 */
class SessionOutputsTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_end_generates_ai_outputs_and_broadcasts(): void
    {
        Event::fake([MeetingStatusBroadcast::class]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-9001', 'title' => 'اجتماع مراجعة عقد',
            'client_name' => $client->name, 'when_label' => 'اليوم', 'status' => 'جارٍ', 'is_up' => true,
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.meetings.end', $meeting), ['attend' => 90])->assertRedirect();

        $meeting->refresh();
        $this->assertSame('منتهٍ', $meeting->status);
        $this->assertNotEmpty($meeting->summary);
        $this->assertNotEmpty($meeting->minutes);
        $this->assertNotEmpty($meeting->decisions);
        Event::assertDispatched(MeetingStatusBroadcast::class);
    }

    public function test_meeting_decisions_convert_to_real_tasks_once(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $meeting = Meeting::create([
            'ref' => 'M-9002', 'title' => 'اجتماع', 'when_label' => 'اليوم', 'status' => 'منتهٍ',
            'decisions' => ['قرار أول', 'قرار ثانٍ'],
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.meetings.tasks', $meeting))->assertRedirect();
        $this->assertSame(2, Task::where('assigned_to', $lawyer->id)->count());
        $this->assertTrue($meeting->fresh()->tasks_created);

        // idempotent — لا يُكرّر
        $this->actingAs($lawyer)->post(route('lawyer.meetings.tasks', $meeting))->assertStatus(409);
        $this->assertSame(2, Task::where('assigned_to', $lawyer->id)->count());
    }

    public function test_meeting_approval_delivers_outputs_to_client(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $meeting = Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-9003', 'title' => 'اجتماع',
            'when_label' => 'اليوم', 'status' => 'منتهٍ', 'is_up' => false,
            'summary' => 'ملخص الاجتماع', 'minutes' => 'محضر الاجتماع',
        ]);

        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertRedirect();
        $this->assertSame('معتمد', $meeting->fresh()->approve);
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());

        // العميل يرى المحضر/الملخص بعد الاعتماد فقط
        $this->actingAs($client)->get(route('meetings'))
            ->assertInertia(fn ($p) => $p->where('meetings.0.minutes', 'محضر الاجتماع')
                ->where('meetings.0.summary', 'ملخص الاجتماع'));
    }

    public function test_consult_end_extracts_decisions_and_converts_to_tasks(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-9100', 'subject' => 'نزاع',
            'channel' => 'مرئية', 'lawyer' => 'أ. سارة القحطاني', 'day' => 'الأحد', 'time' => '10ص',
            'when_label' => 'الأحد', 'session' => 'جلسة جارية', 'status' => 'قيد الاستشارة',
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.consults.end', $consult), ['duration' => '30 دقيقة'])->assertRedirect();
        $consult->refresh();
        $this->assertNotEmpty($consult->summary);
        $this->assertNotEmpty($consult->decisions);

        $this->actingAs($lawyer)->post(route('lawyer.consults.tasks', $consult))->assertRedirect();
        $this->assertSame(count($consult->decisions), Task::where('assigned_to', $lawyer->id)->count());
        $this->assertTrue($consult->fresh()->tasks_created);
    }
}
