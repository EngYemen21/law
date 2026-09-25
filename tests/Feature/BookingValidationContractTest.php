<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **عقدُ المدخلات: مصفوفة (سطحٌ × حمولة).**
 *
 * كان للمشروع طبقتا تحقّق لنفس المفهوم — صارمةٌ في مسار العميل ودعوات الاجتماع،
 * وحرّةٌ في إنشاء الاجتماع وإعادة جدولته وجلسات المحاكم تقبل «الاثنين القادم»
 * و«أمس» وتُخزّن `starts_at = null`. وذلك `null` **يُسكِت التذكيرات** لأن أوامرها
 * تشترط الطابع الزمنيّ: موعدٌ لا يصله تذكيرٌ أبداً، بلا خطأ ولا أثر.
 *
 * **وهذه المصفوفة هي العقد نفسه**: سطحُ حجزٍ جديد لا يظهر فيها يكون غيابه مرئيّاً
 * لمن يقرأ الملفّ — وهو ما لا يوفّره تحقّقٌ مكتوبٌ في كل متحكّم على حدة.
 */
class BookingValidationContractTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    /** الحمولات التي يجب أن يرفضها **كل** سطح. */
    public static function invalidMoments(): array
    {
        return [
            'وقت مستحيل' => ['99:99'],
            'ساعة خارج المدى' => ['25:00'],
            'دقيقة خارج المدى' => ['12:60'],
            'بلا صفر بادئ' => ['7:00'],
            'فارغ' => [''],
            'نصّ عربيّ' => ['الظهر'],
        ];
    }

    /** والتواريخ التي يجب أن يرفضها كل سطح. */
    public static function invalidDays(): array
    {
        return [
            'نصّ عربيّ' => ['الاثنين القادم'],
            'صيغة غير قياسيّة' => ['15/09/2026'],
            'فارغ' => [''],
            'تعبير نسبيّ' => ['tomorrow'],
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    // ══ السطح ١: إنشاء اجتماع ══

    #[DataProvider('invalidDays')]
    public function test_meeting_creation_rejects_an_unparseable_day(string $day): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/meetings', ['title' => 'اجتماع', 'type' => 'اجتماع عميل', 'day' => $day, 'time' => '10:00'])
            ->assertSessionHasErrors('day');

        $this->assertSame(0, Meeting::count(), "«{$day}» أنشأ اجتماعاً");
    }

    #[DataProvider('invalidMoments')]
    public function test_meeting_creation_rejects_an_unparseable_time(string $time): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/meetings', [
                'title' => 'اجتماع', 'type' => 'اجتماع عميل',
                'day' => now()->addDays(3)->toDateString(), 'time' => $time,
            ])
            ->assertSessionHasErrors('time');

        $this->assertSame(0, Meeting::count(), "«{$time}» أنشأ اجتماعاً");
    }

    /** **والافتراضيّ المخترَع زال:** اجتماعٌ بلا تاريخ يُرفض ولا يُخترَع له موعد. */
    public function test_meeting_creation_requires_a_date_instead_of_inventing_one(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/meetings', ['title' => 'اجتماع بلا موعد', 'type' => 'اجتماع عميل'])
            ->assertSessionHasErrors('day');

        $this->assertSame(0, Meeting::count());
    }

    /** والاجتماع الصحيح يُنشأ **بطابعٍ زمنيّ حقيقيّ** — فتعمل تذكيراته. */
    public function test_a_valid_meeting_always_gets_a_real_timestamp(): void
    {
        $day = now()->addDays(3)->toDateString();

        $this->actingAs($this->admin())
            ->post('/admin/meetings', ['title' => 'اجتماع', 'type' => 'اجتماع عميل', 'day' => $day, 'time' => '14:30'])
            ->assertRedirect();

        $meeting = Meeting::latest('id')->firstOrFail();
        $this->assertNotNull($meeting->starts_at, 'بلا طابع زمنيّ لا يصل تذكير أبداً');
        $this->assertSame($day.' 14:30', $meeting->starts_at->format('Y-m-d H:i'));
        $this->assertSame($day.' · 14:30', $meeting->when_label, 'والعرض مشتقٌّ من اللحظة لا من المدخل');
    }

    // ══ السطح ٢: إعادة جدولة اجتماع ══

    #[DataProvider('invalidDays')]
    public function test_meeting_reschedule_rejects_an_unparseable_day(string $day): void
    {
        $meeting = Meeting::create([
            'user_id' => $this->admin()->id, 'ref' => 'M-V-'.uniqid(), 'title' => 'اجتماع',
            'when_label' => 'اليوم · 10:00', 'type' => 'اجتماع', 'status' => 'قادم',
            'starts_at' => now()->addDay(),
        ]);
        $before = $meeting->starts_at;

        $this->actingAs($this->admin())
            ->post("/admin/meetings/{$meeting->id}/reschedule", ['day' => $day, 'time' => '10:00', 'reason' => 'client_request'])
            ->assertSessionHasErrors('day');

        $this->assertEquals($before, $meeting->fresh()->starts_at, 'الموعد القديم يبقى');
    }

    // ══ السطح ٣: جدولة جلسة قضائية ══

    #[DataProvider('invalidDays')]
    public function test_hearing_scheduling_rejects_an_unparseable_day(string $day): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CS-V-'.uniqid(), 'type' => 'نزاع تجاري',
            // «منظورة»: الجلسات تُضاف بعد رفع الدعوى وحدها (حارس 2026-09-11) — «نشطة» ليست حالةَ قضيّة
            'department' => 'القضايا التجارية', 'status' => 'منظورة', 'tone' => 'b-blue',
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->actingAs($lawyer)
            ->post("/lawyer/cases/{$case->getRouteKey()}/hearings", [
                'title' => 'جلسة مرافعة', 'day' => $day, 'time' => '10:00',
            ])
            ->assertSessionHasErrors('day');

        $this->assertSame(0, $case->hearings()->count(), "«{$day}» جدول جلسة");
    }

    // ══ السطح ٤: تحديد موعد الاستشارة (الطاقم بعد السداد — قرار المالك 2026-09-14) ══

    #[DataProvider('invalidMoments')]
    public function test_consult_scheduling_rejects_an_unparseable_time(string $time): void
    {
        Http::fake();
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'department' => 'القضايا التجارية']);

        $ticket = $this->ticketWithApprovedOpinion($client, [
            'number' => 'SB-V-'.uniqid(), 'status' => 'بانتظار حجز الاستشارة',
        ]);
        $consult = $this->requestPricedAndPaid($client, $ticket, 'video');

        $this->adminPublishes($consult, [
            'date' => now()->addDays(2)->toDateString(), 'time' => $time,
        ])->assertJsonValidationErrors('time');

        $this->assertNull($consult->fresh()->starts_at);
    }

    // ══ الحارس البنيويّ ══

    /**
     * **لا سطحَ حجزٍ يقبل يوماً أو وقتاً كسلسلةٍ حرّة.**
     *
     * يمسح المتحكّمات فيُسقط أيّ قاعدةٍ تصف `day`/`time` بـ`string` بدل
     * `date_format` — فلا يعود إغلاق الطبقة الحرّة معتمداً على تذكّر من يُضيف سطحاً.
     */
    public function test_no_controller_validates_a_booking_field_as_a_free_string(): void
    {
        $offenders = [];

        foreach (glob(app_path('Http/Controllers/**/*.php')) + glob(app_path('Http/Controllers/*.php')) as $file) {
            $src = (string) preg_replace('#/\*.*?\*/#su', '', (string) file_get_contents($file));

            foreach (["'day' => [", "'time' => [", "'date' => ["] as $needle) {
                $at = 0;
                while (($at = strpos($src, $needle, $at)) !== false) {
                    $line = substr($src, $at, (int) (strpos($src, ']', $at) - $at) + 1);
                    $at += strlen($needle);

                    if (! str_contains($line, 'date_format')) {
                        $offenders[] = basename(dirname($file)).'/'.basename($file).': '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "حقلُ حجزٍ بلا `date_format`:\n".implode("\n", $offenders));
    }
}
