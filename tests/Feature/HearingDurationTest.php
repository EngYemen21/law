<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\HearingStatus;
use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **جلسة المحكمة بلا نهايةٍ منقوشة — والمدّة المتوقّعة يُدخلها الطاقم إن عرفها** (قرار المالك 2026-09-26).
 *
 * كان التقويم يكتب لكلّ جلسةٍ `DTEND` بعد ستّين دقيقة لا يعرفها أحد. صار: مدّةٌ مُدخلة ⇒ النهاية
 * البداية + المدّة (`CaseHearing::endsAt`)؛ ولا مدّة ⇒ لا نهاية أصلاً. و«انعقدت/فاتت» من الحالة المسجّلة.
 */
class HearingDurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Mail::fake();
    }

    /** @return array{0:User,1:User,2:LegalCase} */
    private function lawyerAndCase(string $status = 'منظورة'): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $case = LegalCase::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'number' => 'CASE-2026-6400', 'type' => 'نزاع تجاري', 'status' => $status, 'tone' => 'b-blue', 'update_text' => '—',
        ]);

        return [$client, $lawyer, $case];
    }

    private function hearing(LegalCase $case, array $attrs = []): CaseHearing
    {
        $at = now()->addDays(3)->setTime(10, 0);

        return $case->hearings()->create($attrs + [
            'title' => 'جلسة المرافعة', 'day' => $at->toDateString(), 'time' => '10:00', 'court' => 'الدائرة التجارية الأولى',
            'status' => HearingStatus::Scheduled->value, 'starts_at' => $at,
        ]);
    }

    /** حدث الجلسة في تغذية التقويم — من `UID` حتى نهاية الحدث. */
    private function feedEvent(User $viewer, CaseHearing $hearing): string
    {
        $body = (string) $this->get(route('calendar.feed', ['user' => $viewer->id, 'token' => $viewer->calendarToken()]))
            ->assertOk()->getContent();
        $from = strpos($body, 'UID:HEARING-'.$hearing->id.'@');
        $this->assertNotFalse($from, 'الجلسة غائبة عن التغذية');

        return substr($body, $from, strpos($body, 'END:VEVENT', $from) - $from);
    }

    private function utc(\DateTimeInterface $at): string
    {
        return Carbon::parse($at)->utc()->format('Ymd\THis\Z');
    }

    public function test_scheduling_with_a_duration_writes_the_exact_end_everywhere(): void
    {
        [$client, $lawyer, $case] = $this->lawyerAndCase();
        $day = now()->addDays(7)->toDateString();

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $case), [
            'title' => 'جلسة المرافعة', 'day' => $day, 'time' => '10:00', 'court' => 'الدائرة الأولى', 'duration_min' => 90,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $h = $case->hearings()->firstOrFail();
        $this->assertSame(90, $h->duration_min);
        $this->assertSame($day.' 11:30', $h->endsAt()->format('Y-m-d H:i'), 'النهاية البداية + المدّة المُدخلة');
        $this->assertSame(90, $h->toData()['durationMin'], 'والواجهة تعرض المدّة');

        $event = $this->feedEvent($client, $h);
        $this->assertStringContainsString('DTSTART:'.$this->utc($h->starts_at), $event);
        $this->assertStringContainsString('DTEND:'.$this->utc($h->starts_at->copy()->addMinutes(90)), $event);
    }

    public function test_without_a_duration_there_is_no_invented_end(): void
    {
        [$client, $lawyer, $case] = $this->lawyerAndCase();

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $case), [
            'title' => 'جلسة المرافعة', 'day' => now()->addDays(7)->toDateString(), 'time' => '10:00', 'duration_min' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $h = $case->hearings()->firstOrFail();
        $this->assertNull($h->duration_min);
        $this->assertNull($h->endsAt(), 'لا مدّة ⇒ لا نهاية');
        $this->assertNull($h->toData()['endsAt']);

        $event = $this->feedEvent($client, $h);
        $this->assertStringContainsString('DTSTART:'.$this->utc($h->starts_at), $event);
        $this->assertStringNotContainsString('DTEND', $event, 'لا ستّين دقيقة مختلَقة — حدثٌ عند لحظة بدايته');
        $this->assertStringNotContainsString('المدّة المتوقّعة', $event);
    }

    public function test_the_duration_is_validated_with_an_arabic_message(): void
    {
        [, $lawyer, $case] = $this->lawyerAndCase();
        $base = ['title' => 'جلسة', 'day' => now()->addDays(7)->toDateString(), 'time' => '10:00'];

        foreach ([CaseHearing::DURATION_MIN - 1, CaseHearing::DURATION_MAX + 1, 'ساعة'] as $bad) {
            $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $case), $base + ['duration_min' => $bad])
                ->assertSessionHasErrors('duration_min');
            $this->assertStringContainsString('المدّة المتوقّعة', (string) session('errors')->first('duration_min'), 'الرسالة بالعربيّة باسم الحقل');
        }

        $this->assertSame(0, $case->hearings()->count());
    }

    public function test_editing_the_duration_alone_is_an_in_place_correction_not_a_postponement(): void
    {
        [$client, $lawyer, $case] = $this->lawyerAndCase();
        $h = $this->hearing($case);
        $slot = ['title' => $h->title, 'day' => $h->starts_at->toDateString(), 'time' => '10:00', 'court' => $h->court];

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.update', [$case, $h]), $slot + ['duration_min' => 45])
            ->assertRedirect()->assertSessionHasNoErrors();

        $h->refresh();
        $this->assertSame(45, $h->duration_min);
        $this->assertSame(HearingStatus::Scheduled->value, $h->status, 'الموعد لم يتحرّك فلا تأجيل');
        $this->assertSame(1, $case->hearings()->count());
        $this->assertFalse(UserNotification::where('user_id', $client->id)->exists(), 'تصحيحٌ لا يُبلَّغ به العميل');

        // نداءٌ بلا حقل المدّة (قديم) لا يمحوها
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.update', [$case, $h]), ['title' => 'جلسة المرافعة الثانية'] + $slot)
            ->assertSessionHasNoErrors();
        $this->assertSame(45, $h->fresh()->duration_min);

        // وإفراغ الحقل صراحةً يعيدها بلا مدّة
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.update', [$case, $h]), $slot + ['duration_min' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($h->fresh()->duration_min);
        $this->assertNull($h->fresh()->endsAt());
    }

    public function test_postponing_carries_the_entered_duration_to_the_new_hearing_only(): void
    {
        [, $lawyer, $case] = $this->lawyerAndCase();
        $old = $this->hearing($case, ['duration_min' => 30]);
        $day = now()->addDays(20)->toDateString();

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.update', [$case, $old]), [
            'title' => 'جلسة المرافعة', 'day' => $day, 'time' => '09:00', 'duration_min' => 120, 'reason' => 'court_decision',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $next = CaseHearing::where('postponed_from_id', $old->id)->firstOrFail();
        $this->assertSame(120, $next->duration_min);
        $this->assertSame($day.' 11:00', $next->endsAt()->format('Y-m-d H:i'));
        $this->assertSame(30, $old->fresh()->duration_min, 'المحضر المؤجَّل يبقى كما كان');
    }

    public function test_postponing_without_a_duration_leaves_the_new_hearing_without_an_end(): void
    {
        [, $lawyer, $case] = $this->lawyerAndCase();
        $old = $this->hearing($case, ['duration_min' => 30]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.update', [$case, $old]), [
            'title' => 'جلسة المرافعة', 'day' => now()->addDays(20)->toDateString(), 'time' => '09:00', 'reason' => 'court_decision',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $next = CaseHearing::where('postponed_from_id', $old->id)->firstOrFail();
        $this->assertNull($next->duration_min, 'الافتراض فارغ — جلسةٌ أخرى لا تُورَّث مدّةً لم تُدخل لها');
        $this->assertNull($next->endsAt());
    }

    public function test_the_first_hearing_from_najiz_registration_takes_an_optional_duration(): void
    {
        [, $lawyer, $case] = $this->lawyerAndCase('بانتظار القيد');

        $this->actingAs($lawyer)->post(route('lawyer.cases.najiz.register', $case), [
            'case_no' => '4700555222', 'court' => 'المحكمة التجارية بالرياض', 'circuit' => 'الدائرة الخامسة',
            'registered_at' => now()->toDateString(), 'hearing_day' => now()->addDays(10)->toDateString(),
            'hearing_time' => '09:00', 'hearing_mode' => 'حضورية', 'hearing_duration_min' => 60,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(60, $case->hearings()->firstOrFail()->duration_min);
    }

    public function test_held_or_lapsed_comes_from_the_recorded_status_not_the_end(): void
    {
        [, , $case] = $this->lawyerAndCase();
        // بدأت قبل ساعة ومدّتها عشر ساعات: نهايتها لم تحِن، وحالتها المسجّلة وحدها تقرّر
        $held = $this->hearing($case, ['starts_at' => now()->subHour(), 'duration_min' => 600, 'status' => HearingStatus::Held->value]);
        $this->assertFalse($held->isLapsed());
        $this->assertTrue($held->endsAt()->isFuture());

        $long = $this->hearing($case, ['starts_at' => now()->subHour(), 'duration_min' => 600]);
        $bare = $this->hearing($case, ['starts_at' => now()->subHour()]);
        $this->assertSame($bare->isLapsed(), $long->isLapsed(), 'المدّة لا تغيّر اشتقاق الفوات');
    }

    public function test_the_duration_reaches_calendar_and_timeline_cards(): void
    {
        [$client, $lawyer, $case] = $this->lawyerAndCase();
        $this->hearing($case, ['duration_min' => 75]);

        $this->actingAs($lawyer)->get(route('lawyer.calendar'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('events', fn ($events) => collect($events)->contains(fn ($e) => $e['kindKey'] === 'hearing' && $e['durationMin'] === 75)));
    }

    /**
     * **حارس: لا تعود نهايةٌ منقوشة للجلسة.** كلّ موضعٍ يكتب نهاية جلسة المحكمة أو يعرضها يقرأ
     * `duration_min` (عبر `CaseHearing::endsAt` أو مباشرةً للتقويم) — لا ستّين ولا رقمٌ اسميّ.
     */
    public function test_guard_no_fixed_end_returns_for_hearings(): void
    {
        $violations = [];
        $files = [
            'app/Services/IcalendarService.php',
            'app/Models/CaseHearing.php',
            'app/Http/Controllers/Concerns/ManagesCourtProceedings.php',
            'app/Http/Controllers/Lawyer/CalendarController.php',
            'app/Http/Controllers/Employee/CalendarController.php',
            'app/Support/TimelineCard.php',
            'app/Console/Commands/SendHearingReminders.php',
            'app/Console/Commands/AutoLapseHearings.php',
            'resources/js/lib/case-ui.tsx',
            'resources/js/lib/case-court.tsx',
            'resources/js/lib/calendar-ui.tsx',
        ];
        foreach ($files as $path) {
            $code = (string) file_get_contents(base_path($path));
            if (preg_match('/addMinutes\(\s*60\s*\)|addHours?\(\s*1?\s*\)\s*->\s*format|durationMinutes:\s*\d+|60\s*\*\s*60\s*\*\s*1000/', $code)) {
                $violations[] = "{$path}: نهايةٌ منقوشة (٦٠ دقيقة)";
            }
        }

        // كتلة الجلسات في التغذية تقرأ المدّة المُدخلة لا الرقم الاسميّ للاستشارة
        $ical = (string) file_get_contents(base_path('app/Services/IcalendarService.php'));
        $from = (int) strpos($ical, "'HEARING-'");
        $block = substr($ical, $from, (int) strpos($ical, "'APPT-'", $from) - $from);
        if (! str_contains($block, 'durationMinutes: $h->duration_min') || str_contains($block, 'nominalMinutes')) {
            $violations[] = 'IcalendarService: DTEND الجلسة لا يأتي من duration_min';
        }

        // والنهاية تُشتقّ في CaseHearing من المدّة وحدها
        $model = (string) file_get_contents(base_path('app/Models/CaseHearing.php'));
        preg_match_all('/addMinutes\(([^)]*)\)/', $model, $m);
        foreach ($m[1] as $arg) {
            if (! str_contains($arg, 'duration_min')) {
                $violations[] = "CaseHearing: addMinutes({$arg}) — نهايةٌ من غير المدّة المُدخلة";
            }
        }

        $this->assertSame([], $violations, "عادت نهايةٌ منقوشة لجلسة المحكمة:\n".implode("\n", $violations));
    }
}
