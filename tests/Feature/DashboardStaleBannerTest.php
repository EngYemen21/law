<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\AdminDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **بنراتُ صفحات الرئيسية لا تَعِد بما انقضى** (بلاغ المالك 2026-09-13).
 *
 * البلاغ: «سوّيتُ جلسة استشارة، وبعد انتهائها يجب أن يزول البنر من صفحة الرئيسية» — ولا يزول.
 *
 * الجذر ثلاثيّ:
 * ١. `Appointment::liveState()` كانت تفحص «منتهية» **بعد** الساعة، فجلسةٌ خُتمت في دقيقتها
 *    العشرين من خانةٍ مدّتها ساعة تبقى «قادمة» أربعين دقيقة.
 * ٢. ختمُ الجلسة لا يمسّ صفّ الموعد إطلاقاً — بينما **الإلغاء** يمسّه (`reschedule`).
 * ٣. البنر يقرأ عمود `when_kind` المخزَّن، ولا يكتبه مسارٌ حيّ بقيمة `'today'` أصلاً، وهو
 *    لا يتحدّث بمرور الوقت.
 *
 * والقاعدة التي تجمعها: الاشتقاق المتساهل صوابٌ **للحارس** (لا تمنع أحداً بالخطأ)، وخطأٌ
 * **للإعلان** (لا تَعِد أحداً بالخطأ).
 */
class DashboardStaleBannerTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    /** استشارة مرئية اليوم بخانةٍ مدّتها ساعة، وموعدُها المرافق. */
    private function consultToday(User $client, array $consultOver = [], array $apptOver = []): Consult
    {
        // **وقتٌ حتميّ.** `startOfHour()->subMinutes(20)` يقع في اليوم السابق حين تعمل
        // الحزمة قرب منتصف الليل، فيصير تأكيد «لا بنر اليوم» خاوياً يمرّ بلا أن يقيس شيئاً
        // (كشفه اختبارُ الطفرة). الظهيرة تضمن أن الموعد «اليوم» وأنّ خانته لم تنقضِ بعد.
        $this->travelTo(now()->startOfDay()->addHours(12)->addMinutes(20));
        $startsAt = now()->copy()->subMinutes(5);   // بدأت قبل خمس دقائق — داخل مهلة الفوات (10) وخانتها ساعة

        // الربط `consults.appointment_id` — الموعد يُنشأ أوّلاً ثمّ تشير إليه الاستشارة
        $appt = Appointment::create(array_merge([
            'user_id' => $client->id, 'ext_id' => 'AP-BNR-'.uniqid(),
            'type' => 'استشارة مرئية', 'ico' => 'video', 'lawyer' => 'أ. سارة',
            'day' => 'اليوم', 'time' => $startsAt->format('H:i'), 'starts_at' => $startsAt,
            'duration_min' => 60, 'place' => 'اجتماع إلكتروني', 'status' => 'مؤكد',
            'tone' => 'b-green', 'when_kind' => 'up',
        ], $apptOver));

        $consult = Consult::create(array_merge([
            'user_id' => $client->id, 'appointment_id' => $appt->id,
            'ref' => 'CN-BNR-'.uniqid(), 'subject' => 'نزاع تجاري',
            'channel' => 'مرئية', 'lawyer' => 'أ. سارة', 'status' => 'قيد الاستشارة',
            'session' => 'جلسة جارية', 'starts_at' => $startsAt, 'duration_min' => 60,
        ], $consultOver));

        return $consult->fresh();
    }

    /** بنرات مركز التنبيهات كما يراها العميل على صفحته الرئيسية. */
    private function alertTitles(User $client): array
    {
        $titles = [];
        $this->actingAs($client)->get(route('dashboard'))->assertOk()
            ->assertInertia(function ($page) use (&$titles) {
                foreach ((array) $page->toArray()['props']['actionAlerts'] as $a) {
                    $titles[] = (string) ($a['title'] ?? '');
                }
            });

        return $titles;
    }

    // ── العطل المبلَّغ ──

    /** جلسةٌ خُتمت قبل نهاية خانتها ⇒ لا بنر «موعد اليوم» ولا «جاهزة للانضمام». */
    public function test_ending_a_session_early_removes_the_home_banner_at_once(): void
    {
        $client = $this->client();
        $consult = $this->consultToday($client);

        // قبل الختم: الجلسة جارية فعلاً — البنر مشروع
        $this->assertNotEmpty($this->alertTitles($client), 'قبل الختم يظهر بنر');

        // المحامي يختم الجلسة — والخانة المحجوزة لم تنقضِ بعد
        $consult->update(['session' => 'منتهية', 'status' => 'منتهية']);

        $titles = $this->alertTitles($client);
        $this->assertNotContains('لديك موعد استشارة مجدول اليوم 📅', $titles, 'البنر يزول بانتهاء الجلسة لا بانقضاء وقتها');
        $this->assertNotContains('جلستك المرئية جاهزة للانضمام الآن 🔴', $titles);
    }

    /** والموعد نفسه يخرج من «القادمة» فوراً — لا ينتظر الساعة. */
    public function test_a_concluded_session_marks_its_appointment_past_before_the_clock(): void
    {
        $client = $this->client();
        $consult = $this->consultToday($client);
        $appt = $consult->appointment;

        $this->assertFalse($appt->isPast(), 'الخانة لم تنقضِ بعد');
        $this->assertSame('up', $appt->liveState()[0]);

        $consult->update(['session' => 'منتهية']);

        $this->assertSame('past', $appt->fresh()->liveState()[0], 'المقياس انتهاء الجلسة لا انقضاء الخانة');
        $this->assertSame('تم الحضور', $appt->fresh()->liveState()[1]);
    }

    /** وختمُ الجلسة من شاشة الطاقم يحسم صفّ الموعد كما يحسمه الإلغاء. */
    public function test_ending_from_the_staff_screen_settles_the_appointment_row(): void
    {
        $client = $this->client();
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consultToday($client, ['assigned_lawyer_id' => $lawyer->id, 'lawyer' => $lawyer->name]);
        $appt = $consult->appointment;

        $this->assertSame('up', $appt->when_kind);

        $this->actingAs($lawyer)->post(route('lawyer.consults.end', $consult), ['notes' => 'تمّت المناقشة.']);

        $appt->refresh();
        $this->assertSame('past', $appt->when_kind, 'العمود المخزَّن يوافق الاشتقاق — لا مصدران للحقيقة');
        $this->assertSame('منتهية', $consult->fresh()->session);
    }

    // ── النظائر ──

    /** موعدٌ بلا وقتٍ محلَّل ليس مجدولاً «اليوم» — والعمود المخزَّن لا يصنع موعداً. */
    public function test_an_appointment_without_a_time_is_never_announced_as_today(): void
    {
        $client = $this->client();
        Appointment::create([
            'user_id' => $client->id, 'ext_id' => 'AP-NULL-'.uniqid(),
            'type' => 'استشارة حضورية', 'ico' => 'user', 'lawyer' => 'أ. سارة',
            'day' => 'يُحدَّد لاحقاً', 'time' => '—', 'starts_at' => null,
            'duration_min' => 60, 'place' => 'المكتب', 'status' => 'مؤكد',
            'tone' => 'b-green', 'when_kind' => 'today',
        ]);

        $this->assertNotContains('لديك موعد استشارة مجدول اليوم 📅', $this->alertTitles($client));
    }

    /** وجلسةٌ بلا موعدٍ بُدئت ولم تُختم لا يُعلَن عليها زرٌّ أحمر عاجل إلى الأبد. */
    public function test_a_started_session_without_a_time_is_never_announced_as_ready_to_join(): void
    {
        $client = $this->client();
        $appt = Appointment::create([
            'user_id' => $client->id, 'ext_id' => 'AP-NJ-'.uniqid(),
            'type' => 'استشارة مرئية', 'ico' => 'video', 'lawyer' => 'أ. سارة',
            'day' => '—', 'time' => '—', 'starts_at' => null, 'duration_min' => 45,
            'place' => 'اجتماع إلكتروني', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);
        $consult = Consult::create([
            'user_id' => $client->id, 'appointment_id' => $appt->id,
            'ref' => 'CN-NULL-'.uniqid(), 'subject' => 'استشارة فوريّة',
            'channel' => 'مرئية', 'lawyer' => 'أ. سارة', 'status' => 'قيد الاستشارة',
            'session' => 'جلسة جارية', 'starts_at' => null,
        ]);

        $this->assertNotContains('جلستك المرئية جاهزة للانضمام الآن 🔴', $this->alertTitles($client));
        // والحارس يبقى متساهلاً كما صُمّم: جلسةٌ بلا موعد تُبدأ يدوياً وتبقى قابلة للدخول
        $this->assertTrue($consult->canJoin(), 'الحارس لا يُمنع — الإعلان وحده هو ما قُيّد');
    }

    /** جلسة محكمة انتهت صباحاً لا تُعلَن «اليوم 🔴» مساءً في لوحة المحامي. */
    public function test_a_hearing_that_ended_this_morning_leaves_the_lawyer_upcoming_list(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->givePermissionTo(Permission::findOrCreate('إدارة القضايا والأتعاب'));
        $client = $this->client();

        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-HRG-'.uniqid(), 'type' => 'تجاري',
            'status' => 'قيد النظر', 'tone' => 'b-blue',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);
        CaseHearing::create([
            'case_id' => $case->id, 'title' => 'جلسة المرافعة', 'day' => 'اليوم', 'time' => '09:00 ص',
            'court' => 'المحكمة التجارية',
            'starts_at' => now()->startOfDay()->addHours(9), 'status' => 'مجدولة',
        ]);

        // لا تُقاس إلا بعد أن تمضي التاسعة صباحاً فعلاً
        $this->travelTo(now()->startOfDay()->addHours(18));

        $this->actingAs($lawyer)->get(route('lawyer.dashboard'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('stats.upcomingHearings', 0));
    }

    /** وعدّاد «استشارة مرئية قادمة» في لوحة الإدارة يقيس ما تقوله كلمته. */
    public function test_the_admin_upcoming_sessions_counter_counts_only_real_future_visual_sessions(): void
    {
        $client = $this->client();

        // (أ) «جديدة» فات موعدها — كانت تُعدّ «قادمة»
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-OLD-'.uniqid(), 'subject' => 'قديمة',
            'channel' => 'مرئية', 'lawyer' => 'أ. سارة', 'status' => 'جديدة',
            'session' => 'بانتظار الجلسة', 'starts_at' => now()->subWeeks(3),
        ]);
        // (ب) هاتفية قادمة — ليست مرئية
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-PH-'.uniqid(), 'subject' => 'هاتفية',
            'channel' => 'هاتفية', 'lawyer' => 'أ. سارة', 'status' => 'جديدة',
            'session' => 'بانتظار الجلسة', 'starts_at' => now()->addDays(2),
        ]);
        // (ج) مرئية قادمة فعلاً — الوحيدة التي تُعدّ
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-UP-'.uniqid(), 'subject' => 'قادمة',
            'channel' => 'مرئية', 'lawyer' => 'أ. سارة', 'status' => 'جديدة',
            'session' => 'بانتظار الجلسة', 'starts_at' => now()->addDays(2),
        ]);

        $overview = app(AdminDashboardService::class)->get360Data(true)['overview'];

        $this->assertSame(1, $overview['upcomingSessions']);
    }
}
