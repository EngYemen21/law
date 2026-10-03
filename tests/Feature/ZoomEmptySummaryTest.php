<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\MeetingStatus;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ZoomService;
use App\Support\ConsultSummary;
use App\Support\MeetingSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **ملخّص Zoom الفارغ لا يُعدّ وصولاً، ولا تُستخرج منه قرارات** (ثبت على الإنتاج 2026-10-03).
 *
 * M-2026-3390 (35 ث) و M-2026-5888 (132 ث): أكمل Zoom ملخّصيهما بحقولٍ فارغة، فقبلهما الاستعلام
 * (`meetingSummary`) وختم `zoom_summary_at` على عنواننا وحده «ملخص الاجتماع — M-…»، ثمّ أُرسل العنوان
 * لاستخراج القرارات فاختلق النموذج 3 و5 قرارات («إعداد مسودة جدول أعمال الاجتماع القادم»…) صارت مهامّ مقترحة.
 * مسار الويبهوك (`summaryFromPayload`) كان يرفض الفارغ؛ الحكم الآن في موضعٍ واحد للمسارين.
 */
class ZoomEmptySummaryTest extends TestCase
{
    use RefreshDatabase;

    /** كما أعاده Zoom: 200 بحقولٍ فارغة. */
    private const EMPTY_SUMMARY = [
        'meeting_uuid' => 'abc==', 'summary_content' => '', 'summary_overview' => '',
        'summary_details' => [['label' => 'Meeting summary', 'summary' => '']], 'next_steps' => [' '],
    ];

    private function fakeZoom(array $summary): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/past_meetings/*/instances' => Http::response(['meetings' => []]),
            'api.zoom.us/v2/meetings/*/meeting_summary' => Http::response($summary),
            '*' => Http::response([], 404),
        ]);
    }

    public function test_the_api_does_not_return_an_empty_summary(): void
    {
        $this->fakeZoom(self::EMPTY_SUMMARY);

        $this->assertNull(app(ZoomService::class)->meetingSummary('81282041476'));
        $this->assertNull(ZoomService::summaryFromPayload(self::EMPTY_SUMMARY), 'والويبهوك بالحكم نفسه');
    }

    public function test_an_empty_meeting_summary_is_not_stored_and_invents_no_decisions(): void
    {
        $this->fakeZoom(self::EMPTY_SUMMARY);
        $meeting = Meeting::create([
            'ref' => 'M-EMPTY-1', 'title' => 'إغلاق قضية', 'type' => 'اجتماع مرتبط بقضية', 'when_label' => 'اليوم',
            'starts_at' => now()->subHour(), 'status' => MeetingStatus::Ended->value, 'meet_id' => '81282041476',
        ]);

        MeetingSummary::pull($meeting, app(ZoomService::class));

        $fresh = $meeting->fresh();
        $this->assertNull($fresh->zoom_summary_at, 'الفارغ ليس وصولاً — يبقى الجلب الدوريّ يحاول');
        $this->assertNull($fresh->zoom_summary, 'ولا يُعرض مربّعٌ بعنوانٍ وحده');
        $this->assertEmpty($fresh->decisions);
        $this->assertEmpty($fresh->suggested_tasks);
        $this->assertSame(0, AiRun::where('task_type', 'meeting.decisions')->count(), 'لا يُنادى النموذج على عنوانٍ بلا محتوى');
    }

    public function test_an_empty_consult_summary_is_not_stored_either(): void
    {
        $this->fakeZoom(self::EMPTY_SUMMARY);
        $consult = Consult::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'ref' => 'CN-EMPTY-1', 'subject' => 'نزاع',
            'channel' => 'مرئية', 'lawyer' => 'محامٍ', 'session' => 'منتهية', 'status' => 'منتهية', 'meet_id' => '81282041477',
        ]);

        ConsultSummary::pull($consult, app(ZoomService::class));

        $fresh = $consult->fresh();
        $this->assertNull($fresh->zoom_summary_at);
        $this->assertEmpty($fresh->suggested_tasks);
        $this->assertSame(0, AiRun::where('task_type', 'meeting.decisions')->count());
    }

    public function test_a_real_summary_is_still_stored(): void
    {
        $this->fakeZoom(['meeting_uuid' => 'abc==', 'summary_content' => 'ناقش الطرفان إغلاق القضية وتسليم المستندات.']);

        $this->assertStringContainsString('إغلاق القضية', (string) app(ZoomService::class)->meetingSummary('81282041476')['content']);
    }
}
