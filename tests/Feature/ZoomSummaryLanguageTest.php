<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ZoomService;
use App\Support\ConsultSummary;
use App\Support\MeetingSummary;
use App\Support\ZoomSummaryText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **ملخّص Zoom بغير العربيّة لا يُنشر تلقائيّاً، والحقل الموحّد يُقرأ** (قرار المالك 2026-10-03).
 *
 * ثبت على CN-2026-6349: كلامٌ عربيّ فرّغه Zoom بلغة الكلام الافتراضيّة (الإنجليزيّة — Zoom KB0062813) فوصل
 * الملخّص إنجليزيّاً مشوّهاً، وملأ خانة الملخّص التي يقرؤها العميل. الآن يبقى في `zoom_summary` للطاقم وحده.
 * و`summary_content` حقل Zoom الموحّد (الحقول القديمة «deprecated» في مرجع API) يُقرأ أوّلاً.
 */
class ZoomSummaryLanguageTest extends TestCase
{
    use RefreshDatabase;

    private const ENGLISH = ['summary_overview' => 'The transcript appeared to be a series of disjointed greetings and statements.',
        'summary_details' => [['label' => 'Insufficient Content for Summary', 'summary' => 'The conversation does not provide sufficient context.']],
        'next_steps' => []];

    private const ARABIC = ['summary_overview' => 'عرض العميل نزاعاً على مستخلصات عقد مقاولة.',
        'summary_details' => [['label' => 'الرأي القانوني', 'summary' => 'يُنصح بتوجيه إنذار رسمي قبل رفع الدعوى.']],
        'next_steps' => ['توجيه إنذار للمقاول']];

    private function consult(): Consult
    {
        return Consult::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'ref' => 'CN-LANG-'.uniqid(), 'subject' => 'نزاع',
            'channel' => 'مرئية', 'lawyer' => 'محامٍ', 'session' => 'منتهية', 'status' => 'منتهية', 'meet_id' => '81823767754',
        ]);
    }

    private function meeting(): Meeting
    {
        return Meeting::create([
            'ref' => 'M-LANG-'.uniqid(), 'title' => 'اجتماع', 'type' => 'اجتماع مع عميل', 'when_label' => 'اليوم',
            'starts_at' => now()->subHour(), 'status' => MeetingStatus::Ended->value, 'meet_id' => '81823767755',
        ]);
    }

    public function test_an_english_consult_summary_stays_with_the_staff(): void
    {
        $consult = $this->consult();

        $this->assertTrue(ConsultSummary::pull($consult, app(ZoomService::class), self::ENGLISH));

        $fresh = $consult->fresh();
        $this->assertNull($fresh->summary, 'العميل لا يقرأ ملخّصاً إنجليزيّاً مشوّهاً');
        $this->assertStringContainsString('disjointed greetings', (string) $fresh->zoom_summary, 'والطاقم يراه');
        $this->assertNotNull($fresh->zoom_summary_at);
    }

    public function test_an_arabic_consult_summary_is_published_as_before(): void
    {
        $consult = $this->consult();

        ConsultSummary::pull($consult, app(ZoomService::class), self::ARABIC);

        $this->assertStringContainsString('يُنصح بتوجيه إنذار', (string) $consult->fresh()->summary);
    }

    public function test_an_english_meeting_summary_fills_neither_summary_nor_minutes(): void
    {
        $meeting = $this->meeting();

        MeetingSummary::pull($meeting, app(ZoomService::class), self::ENGLISH);

        $fresh = $meeting->fresh();
        $this->assertTrue(ZoomSummaryText::isPlaceholderSummary($fresh->summary));
        $this->assertTrue(ZoomSummaryText::isPlaceholderMinutes($fresh->minutes));
        $this->assertStringContainsString('disjointed greetings', (string) $fresh->zoom_summary);

        $arabic = $this->meeting();
        MeetingSummary::pull($arabic, app(ZoomService::class), self::ARABIC);
        $this->assertStringContainsString('يُنصح بتوجيه إنذار', (string) $arabic->fresh()->minutes);
    }

    /** الحكم على نصّ Zoom وحده — عناويننا العربيّة حوله لا تجعل الإنجليزيّ عربيّاً. */
    public function test_the_language_is_judged_on_zoom_text_only(): void
    {
        $english = ZoomService::summaryFromPayload(self::ENGLISH);
        $this->assertFalse(ZoomSummaryText::isArabic($english));
        $this->assertStringContainsString('نظرة عامة', ZoomSummaryText::format('ملخص الاستشارة — CN-1', $english));

        $this->assertTrue(ZoomSummaryText::isArabic(ZoomService::summaryFromPayload(self::ARABIC)));
        $this->assertTrue(ZoomSummaryText::isArabic(ZoomService::summaryFromPayload(['summary_content' => 'ناقش الطرفان بند الغرامة في عقد SaaS مع شركة ABC.'])), 'مصطلحٌ لاتينيّ داخل نصٍّ عربيّ لا يُسقطه');
    }

    /** الحقل الموحّد يُقرأ نصّاً بفقراته — بلا رموز Markdown ولا وسوم. */
    public function test_the_unified_summary_content_is_read_first(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response([
                'summary_content' => "## الوقائع\n\nعرض العميل **نزاع المقاولة**.\n\n- توجيه إنذار\n- <script>x</script>",
                'summary_overview' => 'نصٌّ قديم',
            ]),
        ]);

        $out = app(ZoomService::class)->meetingSummary('81823767754');
        $text = ZoomSummaryText::format('ملخص الاستشارة — CN-1', $out);

        $this->assertStringContainsString("الوقائع\n\nعرض العميل نزاع المقاولة.", $text);
        $this->assertStringContainsString('توجيه إنذار', $text);
        $this->assertStringNotContainsString('**', $text);
        $this->assertStringNotContainsString('<script', $text);
        $this->assertStringNotContainsString('نصٌّ قديم', $text, 'الموحّد يحمل الملخّص كاملاً — لا يُكرَّر بالقديم');
    }
}
