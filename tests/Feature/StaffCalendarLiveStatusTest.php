<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\User;
use App\Support\EventStatus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * لوحتا الموظف والمحامي تعرضان الحالة **الحيّة** لا المخزَّنة.
 *
 * أبلغ مستخدم عن تناقض: اجتماع يُعرض «قادم» بينما الدخول يردّ «انتهت الجلسة». أُصلح في
 * تقويم العميل، والفحص أثبت أن اللوحتين الأخريين مصابتان بنفس العطل — ستّة مواضع تقرأ
 * العمود المخزَّن.
 *
 * والعمود يتأخّر **عمداً**: AutoCloseMissedMeetings يُمهل 12 ساعة، وliveState موجودة لسدّ
 * تلك الفجوة. فاجتماع فات قبل ساعات قليلة هو بالضبط الحالة التي تكشف العطل.
 */
class StaffCalendarLiveStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function employee(): User
    {
        $u = User::factory()->create(['role' => Role::Employee]);
        $u->syncPermissions(Permission::all());

        return $u;
    }

    /** اجتماع مخزَّن «قادم» فات قبل 7 ساعات (داخل مهلة الـ12) — الحارس المباشر للعطل المُبلَغ عنه. */
    public function test_a_past_meeting_is_not_shown_upcoming_to_the_employee(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        Meeting::create([
            'user_id' => null, 'ref' => 'M-STALE-E', 'title' => 'تجهيز جلسة',
            'when_label' => 'أمس', 'starts_at' => now()->subHours(7),
            'status' => 'قادم', 'dur' => '60 دقيقة', 'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->actingAs($this->employee())->get('/employee/calendar')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where(
                'events',
                fn ($ev) => collect($ev)->firstWhere('title', 'تجهيز جلسة')['status'] === 'لم ينعقد'
            ));
    }

    /** ونفسه في لوحة المحامي المسنَد. */
    public function test_the_same_holds_for_the_lawyer_calendar(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        Meeting::create([
            'user_id' => null, 'ref' => 'M-STALE-L', 'title' => 'تجهيز جلسة',
            'when_label' => 'أمس', 'starts_at' => now()->subHours(7),
            'status' => 'قادم', 'dur' => '60 دقيقة', 'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->actingAs($lawyer)->get('/lawyer/calendar')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where(
                'events',
                fn ($ev) => collect($ev)->firstWhere('title', 'تجهيز جلسة')['status'] === 'لم ينعقد'
            ));
    }

    /** حارس ضدّ حسم مفرط: اجتماع قادم فعلاً يبقى «قادم». */
    public function test_a_genuinely_upcoming_meeting_stays_upcoming(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        Meeting::create([
            'user_id' => null, 'ref' => 'M-FUT-E', 'title' => 'اجتماع قادم',
            'when_label' => 'غداً', 'starts_at' => now()->addDay(),
            'status' => 'قادم', 'dur' => '60 دقيقة', 'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->actingAs($this->employee())->get('/employee/calendar')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where(
                'events',
                fn ($ev) => collect($ev)->firstWhere('title', 'اجتماع قادم')['status'] === 'قادم'
            ));
    }

    /** جلسة «مجدولة» فات موعدها ⇒ «فائتة — بانتظار النتيجة». */
    public function test_a_lapsed_hearing_is_flagged_pending_result(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-STAFF-1', 'type' => 'نزاع',
            'status' => 'نشطة', 'tone' => 'b-blue',
        ]);
        CaseHearing::create([
            'case_id' => $case->id, 'title' => 'الجلسة الأولى', 'day' => now()->subDay()->format('Y-m-d'),
            'time' => '10:00', 'starts_at' => now()->subDay(), 'court' => 'المحكمة التجارية',
            'status' => 'مجدولة',
        ]);

        $this->actingAs($this->employee())->get('/employee/calendar')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where(
                'events',
                fn ($ev) => collect($ev)->contains(fn ($e) => $e['status'] === EventStatus::HEARING_LAPSED)
            ));
    }

    /** المصدر الواحد يُعيد النصّ نفسه مهما كان المنادي — ضمانة ألّا تتباعد الشاشات مجدّداً. */
    public function test_the_shared_source_is_the_single_authority(): void
    {
        $m = Meeting::create([
            'user_id' => null, 'ref' => 'M-SRC', 'title' => 'اجتماع',
            'when_label' => 'أمس', 'starts_at' => now()->subHours(7),
            'status' => 'قادم', 'dur' => '60 دقيقة',
        ]);

        $this->assertSame($m->liveState()[1], EventStatus::forMeeting($m));
        $this->assertNotSame($m->status, EventStatus::forMeeting($m), 'المصدر يُعيد العمود المخزَّن — لا اشتقاق حيّ.');
    }
}
