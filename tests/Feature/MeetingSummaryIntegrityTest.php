<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Models\Meeting;
use App\Models\User;
use App\Services\LegalAiService;
use App\Services\ZoomService;
use App\Support\MeetingSummary;
use App\Support\ZoomSummaryText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ملخص الاجتماع = بيانات Zoom الحقيقية (مبدأ صاحب المنتج — حادثة M-26753).
 *
 * كان زرّ «إنهاء» يولّد بالذكاء ملخصاً ومحضراً وقرارات من البيانات الوصفية وحدها
 * (عنوان/نوع/تذكرة) — فاختلق لاجتماع لم يدخله أحد مداولاتٍ وأربعة قرارات، واعتُمد
 * وظهر للعميل. وملخص Zoom الحقيقي كان يُكتب في حقل جانبي لا يراه العميل أبداً.
 */
class MeetingSummaryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function endedMeeting(array $extra = []): Meeting
    {
        return Meeting::create(array_merge([
            'ref' => 'M-SUM-'.random_int(100, 999), 'title' => 'اجتماع متابعة',
            'when_label' => 'أمس · 11:00', 'status' => 'منتهٍ', 'meet_id' => '9'.random_int(10 ** 9, 10 ** 10 - 1),
        ], $extra));
    }

    /** حمولة ملخص كما يرسلها ويبهوك Zoom meeting.summary_completed. */
    private function zoomPayload(): array
    {
        return [
            'summary_overview' => 'ناقش الطرفان مستجدات العقد واتفقا على تعديل بند التسليم.',
            'summary_details' => [
                ['label' => 'بند التسليم', 'summary' => 'تمديد مدة التسليم ثلاثين يوماً باتفاق الطرفين.'],
            ],
            'next_steps' => ['صياغة ملحق تعديل العقد'],
        ];
    }

    // ————— ١ · لا اختلاق: بلا محتوى فعلي لا يُنادى الذكاء ولا تُخترع قرارات —————

    public function test_ending_without_content_produces_honest_placeholder_not_fabrication(): void
    {
        config(['services.glm.key' => 'test-key']);
        Http::fake();

        $meeting = $this->endedMeeting();
        (new GenerateMeetingSummaryJob($meeting, ''))->handle(app(LegalAiService::class));

        $meeting->refresh();
        // لا نداء AI إطلاقاً — التلخيص من لا شيء ليس تلخيصاً بل اختلاقاً
        Http::assertNothingSent();
        // ولا قالب وهمي محفوظ (قرار صاحب المنتج): الحقول تبقى فارغة تماماً
        // حتى يصل ملخص Zoom الحقيقي أو يُدوَّن المحضر يدوياً
        $this->assertNull($meeting->summary, 'لا نصّ محفوظ — ولا حتى قالب انتظار');
        $this->assertNull($meeting->minutes, 'لا محضر محفوظ — ولا حتى قالب انتظار');
        $this->assertSame([], $meeting->decisions ?? [], 'لا قرارات مخترعة');
        $this->assertTrue(ZoomSummaryText::isPlaceholderMinutes($meeting->minutes), 'الفراغ قالبي فيملؤه Zoom لاحقاً');
    }

    /** ومع ملاحظات مدوَّنة فعلاً: يُنادى الذكاء وتصل الملاحظات في الطلب (محتوى حقيقي يُلخَّص). */
    public function test_ending_with_notes_summarizes_the_notes(): void
    {
        config(['services.glm.key' => 'test-key']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"summary":"ملخص من الملاحظات","minutes":"نقاش البند الثالث","decisions":["متابعة البند الثالث"]}',
        ]]]], 200)]);

        $meeting = $this->endedMeeting();
        (new GenerateMeetingSummaryJob($meeting, 'نوقش البند الثالث من العقد واتُّفق على متابعته'))->handle(app(LegalAiService::class));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'نوقش البند الثالث'));
        $this->assertSame('ملخص من الملاحظات', $meeting->fresh()->summary);
    }

    // ————— ٢ · ملخص Zoom الحقيقي يحلّ محلّ المعروض للعميل (كما في الاستشارات) —————

    public function test_zoom_summary_replaces_the_client_facing_summary_and_minutes(): void
    {
        $meeting = $this->endedMeeting([
            // الحالة بعد إصلاح ١: نصوص أمينة قالبية بانتظار Zoom
            'summary' => 'ملخص اجتماع «اجتماع متابعة»: تعذّر إعداد الملخّص بالذكاء الاصطناعي حالياً — بحاجة إلى تدوين المحضر يدوياً.',
            'minutes' => "محضر اجتماع: اجتماع متابعة\nتعذّر التوليد الذكي — يُرجى تدوين أبرز ما دار والقرارات يدوياً.",
        ]);

        MeetingSummary::pull($meeting, app(ZoomService::class), $this->zoomPayload());

        $meeting->refresh();
        $this->assertNotNull($meeting->zoom_summary_at);
        // الحقلان اللذان يعرضهما toCard للعميل صارا محتوى Zoom الحقيقي
        $this->assertStringContainsString('تعديل بند التسليم', (string) $meeting->summary, 'الملخص المعروض = بيانات Zoom');
        $this->assertStringContainsString('تمديد مدة التسليم', (string) $meeting->minutes, 'المحضر المعروض = بيانات Zoom');
        $this->assertStringNotContainsString('تعذّر', (string) $meeting->summary, 'النص القالبي زال');
    }

    /** النصّ القالبي «بانتظار Zoom» مملوء تقنياً لكنه ليس مخرجات — لا يُعتمد. */
    public function test_placeholder_outputs_cannot_be_approved(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $meeting = $this->endedMeeting([
            'summary' => 'ملخص اجتماع «اجتماع متابعة»: بانتظار ملخص الجلسة من Zoom — أو تدوين المحضر يدوياً.',
            'minutes' => "محضر اجتماع: اجتماع متابعة\nبانتظار ملخص الجلسة من Zoom — يُرجى تدوين أبرز ما دار والقرارات يدوياً إن لم يصل.",
        ]);

        $this->actingAs($admin)->post(route('admin.meetings.approve', $meeting))->assertStatus(422);
        $this->assertNotSame('معتمد', $meeting->fresh()->approve);
    }

    /**
     * الجلسة المنقطعة = عدّة انعقادات بعدّة ملخصات لدى Zoom — تُدمج كلّها زمنياً كما وردت.
     * سحب الأخير وحده كان يعرض ملخص إعادة الدخول ويُسقط النقاش الفعلي (حادثة M-26753
     * الثانية: بريد Zoom للمضيف حمل ملخص الجزء الأول ونحن عرضنا الثاني ⇒ بدا «محرَّفاً»).
     */
    public function test_multi_instance_meetings_merge_all_zoom_summaries_chronologically(): void
    {
        config(['services.zoom.account_id' => 'a', 'services.zoom.client_id' => 'b', 'services.zoom.client_secret' => 'c']);
        Http::fake([
            'zoom.us/oauth/token*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.zoom.us/v2/past_meetings/*/instances' => Http::response(['meetings' => [
                ['uuid' => 'uuid-B', 'start_time' => '2026-08-25T22:18:02Z'],
                ['uuid' => 'uuid-A', 'start_time' => '2026-08-25T22:11:09Z'],
            ]]),
            'api.zoom.us/v2/meetings/*uuid-A*/meeting_summary' => Http::response([
                'meeting_uuid' => 'uuid-A',
                'summary_overview' => 'ناقش الطرفان مشاكل الصوت لدى حسون وتقرر إعادة الاتصال.',
                'summary_details' => [], 'next_steps' => [],
            ]),
            'api.zoom.us/v2/meetings/*uuid-B*/meeting_summary' => Http::response([
                'meeting_uuid' => 'uuid-B',
                'summary_overview' => 'تبادل الطرفان التحيات بعد إعادة الدخول.',
                'summary_details' => [], 'next_steps' => [],
            ]),
        ]);

        $merged = app(ZoomService::class)->fullMeetingSummary('81400000000');

        $this->assertNotNull($merged);
        $this->assertStringContainsString('مشاكل الصوت لدى حسون', $merged['overview'], 'ملخص الانعقاد الأول حاضر');
        $this->assertStringContainsString('التحيات بعد إعادة الدخول', $merged['overview'], 'وملخص الثاني كذلك');
        $this->assertLessThan(
            mb_strpos($merged['overview'], 'التحيات بعد إعادة الدخول'),
            mb_strpos($merged['overview'], 'مشاكل الصوت لدى حسون'),
            'الترتيب زمني: الأول قبل الثاني رغم ورودهما معكوسين من Zoom'
        );
    }

    // ————— ٣ · زرّ الاستعلام اليدوي من Zoom —————

    /** المسار حيّ لكل البادئات، ويرفض اجتماعاً بلا جلسة Zoom، ويصدق حين لا بيانات لدى Zoom. */
    public function test_manual_zoom_sync_endpoint(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        // بلا meet_id ⇒ 422 لا استعلام عبثي
        $bare = Meeting::create(['ref' => 'M-SYNC-1', 'title' => 'بلا Zoom', 'when_label' => 'أمس', 'status' => 'منتهٍ']);
        $this->actingAs($admin)->post(route('admin.meetings.zoomsync', $bare))->assertStatus(422);

        // بجلسة Zoom لكن بلا بيانات لديه (غير مهيّأ في الاختبار) ⇒ رسالة صادقة لا نجاح كاذب
        $withZoom = $this->endedMeeting();
        $this->actingAs($admin)->post(route('admin.meetings.zoomsync', $withZoom))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNull($withZoom->fresh()->summary, 'لا شيء يُكتب حين لا بيانات لدى Zoom');
    }

    /** المحضر المكتوب يدوياً محتوى بشري حقيقي — لا يُستبدل. */
    public function test_manual_minutes_are_not_overwritten_by_zoom(): void
    {
        $manual = 'محضر مدوَّن يدوياً: حضر الطرفان ونوقشت المطالبة المالية وتقرّر رفع دعوى.';
        $meeting = $this->endedMeeting(['minutes' => $manual, 'summary' => null]);

        MeetingSummary::pull($meeting, app(ZoomService::class), $this->zoomPayload());

        $meeting->refresh();
        $this->assertSame($manual, $meeting->minutes, 'المحضر البشري محفوظ');
        $this->assertStringContainsString('تعديل بند التسليم', (string) $meeting->summary, 'الملخص الفارغ امتلأ من Zoom');
    }
}
