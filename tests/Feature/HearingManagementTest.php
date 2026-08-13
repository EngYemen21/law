<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\HearingEventMail;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * إدارة الجلسات: إعادة الجدولة تعيد ضبط starts_at وتصفّر أختام التذكير وتحدّث «القادمة»؛
 * الإلغاء يضبط «ملغاة» ويصفّي «القادمة»؛ تسجيل النتيجة لم يعد صامتًا.
 */
class HearingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function lawyerAndCase(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $case = LegalCase::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'CASE-2026-6200', 'type' => 'نزاع تجاري', 'status' => 'منظورة', 'tone' => 'b-blue', 'update_text' => '—',
        ]);

        return [$client, $lawyer, $case];
    }

    public function test_add_hearing_computes_starts_at_and_next_hearing(): void
    {
        Mail::fake();
        [$client, $lawyer, $case] = $this->lawyerAndCase();

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $case), [
            'title' => 'الجلسة الأولى', 'day' => '2026-09-20', 'time' => '11:00', 'court' => 'الدائرة التجارية',
        ])->assertRedirect();

        $hearing = CaseHearing::where('case_id', $case->id)->firstOrFail();
        $this->assertNotNull($hearing->starts_at);
        $this->assertSame('2026-09-20 11:00', $hearing->starts_at->format('Y-m-d H:i'));
        $this->assertNotSame('—', $case->fresh()->next_hearing);
        // بريد «إنشاء» للعميل والمحامي
        Mail::assertQueued(HearingEventMail::class, fn ($m) => $m->event === 'created' && $m->hasTo($client->email));
        Mail::assertQueued(HearingEventMail::class, fn ($m) => $m->event === 'created' && $m->hasTo($lawyer->email));
    }

    public function test_reschedule_resets_reminder_stamps_and_updates_next(): void
    {
        Mail::fake();
        [$client, $lawyer, $case] = $this->lawyerAndCase();
        $hearing = $case->hearings()->create([
            'title' => 'جلسة', 'day' => '2026-09-01', 'status' => 'مؤجلة', 'starts_at' => now()->addDay(),
            'reminder_24h_sent_at' => now(), 'reminder_1h_sent_at' => now(),
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.update', [$case, $hearing]), [
            'title' => 'جلسة (مُعاد جدولتها)', 'day' => '2026-10-05', 'time' => '09:30', 'court' => 'الدائرة',
        ])->assertRedirect();

        $fresh = $hearing->fresh();
        $this->assertSame('مجدولة', $fresh->status); // عادت مجدولة
        $this->assertSame('2026-10-05 09:30', $fresh->starts_at->format('Y-m-d H:i'));
        $this->assertNull($fresh->reminder_24h_sent_at); // صُفّرت الأختام لإعادة التذكير
        $this->assertNull($fresh->reminder_1h_sent_at);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $client->id]); // أُشعر العميل
        Mail::assertQueued(HearingEventMail::class, fn ($m) => $m->event === 'rescheduled' && $m->hasTo($client->email));
    }

    public function test_cancel_sets_cancelled_and_clears_next(): void
    {
        Mail::fake();
        [$client, $lawyer, $case] = $this->lawyerAndCase();
        $hearing = $case->hearings()->create(['title' => 'جلسة', 'day' => '2026-09-01', 'status' => 'مجدولة', 'starts_at' => now()->addDay()]);
        $case->update(['next_hearing' => 'موعد ما']);

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.cancel', [$case, $hearing]))->assertRedirect();

        $this->assertSame('ملغاة', $hearing->fresh()->status);
        $this->assertSame('—', $case->fresh()->next_hearing); // لا جلسات مجدولة متبقية
        Mail::assertQueued(HearingEventMail::class, fn ($m) => $m->event === 'cancelled' && $m->hasTo($client->email));
    }

    public function test_record_outcome_is_not_silent(): void
    {
        [$client, $lawyer, $case] = $this->lawyerAndCase();
        $hearing = $case->hearings()->create(['title' => 'جلسة', 'day' => '2026-09-01', 'status' => 'مجدولة', 'starts_at' => now()->addDay()]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.record', [$case, $hearing]), [
            'status' => 'منعقدة', 'outcome' => 'تم سماع أقوال الطرفين وتأجيل النطق بالحكم.',
        ])->assertRedirect();

        $this->assertSame('منعقدة', $hearing->fresh()->status);
        $this->assertSame('تم سماع أقوال الطرفين وتأجيل النطق بالحكم.', $hearing->fresh()->outcome);
        // لم يعد صامتًا: رسالة محادثة + إشعار
        $this->assertTrue($case->messages()->where('role', 'جلسة')->get()->contains(fn ($m) => str_contains($m->body, 'منعقدة')));
        $this->assertDatabaseHas('user_notifications', ['user_id' => $client->id]);
    }
}
