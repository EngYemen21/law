<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **الخادم يفرض ما تُعلنه البطاقة — ويُغلق غرفة Zoom عند الإنهاء.**
 *
 * كان `startable` (نافذة ربع الساعة) يُحسب ويُرسل إلى الواجهة **ولا يُفرض**، فصار
 * توصيةً تتجاهلها شاشةٌ لا تقرؤه: يُبدأ موعدٌ بعد ثلاثة أسابيع، أو فائتٌ منذ شهر،
 * فيُشعَر الموكّل بأن جلسته «بدأت» ولا أحد هناك.
 *
 * و«إنهاء الجلسة» كان يختم السجلّ ولا يُنهي اجتماع Zoom — `ZoomService::endMeeting`
 * لم يكن مستدعىً في مسار الاستشارات إطلاقاً — فتبقى الغرفة حيّةً بعد أن أُعلنت
 * الجلسة منتهية.
 */
class ConsultSessionWindowTest extends TestCase
{
    use RefreshDatabase;

    private function consult(array $overrides = []): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create($overrides + [
            'user_id' => $client->id, 'ref' => 'CN-WIN-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'مؤكد', 'session' => 'بانتظار الجلسة',
            'tone' => 'b-blue', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
        ]);

        return [$consult, $lawyer];
    }

    /**
     * **الحارس الأثمن.** يجمع في اختبارٍ واحد ما تعلنه البطاقة وما يقبله الخادم —
     * والجمعُ هو ما يقفل افتراقهما، وهو الافتراق الذي أنتج العطل أصلاً.
     */
    public function test_the_server_refuses_a_start_the_card_never_offered(): void
    {
        [$consult, $lawyer] = $this->consult(['starts_at' => now()->addWeeks(3)]);

        $this->assertFalse($consult->toCard()['startable'], 'البطاقة لا تعرضه');

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/start")
            ->assertStatus(422);

        $this->assertSame('بانتظار الجلسة', $consult->fresh()->session, 'ولا تُبدأ');
    }

    /** والفائتة كذلك — «بدء» جلسةٍ فات موعدها بشهرٍ إعلانُ حضورٍ لم يقع. */
    public function test_a_missed_session_cannot_be_started(): void
    {
        [$consult, $lawyer] = $this->consult(['starts_at' => now()->subMonth()]);

        $this->assertTrue($consult->isMissed());

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/start")
            ->assertStatus(422);

        $this->assertSame('بانتظار الجلسة', $consult->fresh()->session);
    }

    /** وداخل النافذة تُبدأ — وإلّا كان «الإصلاح» تعطيلاً. */
    public function test_a_session_inside_the_window_still_starts(): void
    {
        [$consult, $lawyer] = $this->consult(['starts_at' => now()->addMinutes(4)]);

        $this->assertTrue($consult->toCard()['startable']);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/start")
            ->assertRedirect();

        $this->assertSame('جلسة جارية', $consult->fresh()->session);
    }

    /**
     * **واستشارةٌ بلا موعد تبقى قابلةً للبدء يدوياً.**
     *
     * فرعٌ يجب أن يبقى: إسقاطه يُعطّل كلّ استشارةٍ لم يُحدَّد موعدها — وهي أكثر
     * الصفوف في القاعدة.
     */
    public function test_a_consult_without_a_scheduled_time_may_still_be_started(): void
    {
        [$consult, $lawyer] = $this->consult(['starts_at' => null]);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/start")
            ->assertRedirect();

        $this->assertSame('جلسة جارية', $consult->fresh()->session);
    }

    /** وبدءُ جلسةٍ جاريةٍ لا يُعدّ خطأً — الويبهوك يسبق الزرّ أحياناً. */
    public function test_starting_an_already_running_session_is_idempotent(): void
    {
        [$consult, $lawyer] = $this->consult(['session' => 'جلسة جارية', 'starts_at' => now()->addWeeks(3)]);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/start")
            ->assertRedirect();

        $this->assertSame('جلسة جارية', $consult->fresh()->session);
    }

    // ══ إنهاء Zoom ══

    /** إنهاء الجلسة يُغلق غرفة Zoom فعلاً — لا يختم السجلّ وحده. */
    public function test_ending_a_session_ends_the_zoom_meeting(): void
    {
        config([
            'services.zoom.account_id' => 'acc', 'services.zoom.client_id' => 'cid',
            'services.zoom.client_secret' => 'secret',
        ]);
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/meetings/*/status' => Http::response('', 204),
            '*' => Http::response([], 200),
        ]);

        [$consult, $lawyer] = $this->consult([
            'session' => 'جلسة جارية', 'status' => 'قيد الاستشارة', 'meet_id' => '81823767754',
        ]);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/end", ['notes' => 'دوّن المستشار وقائع الجلسة.'])
            ->assertRedirect();

        $this->assertSame('منتهية', $consult->fresh()->session);
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), 'api.zoom.us/v2/meetings/81823767754/status'));
    }

    /** وحفظُ تدوينٍ لجلسةٍ مختومة لا يُعيد إنهاء اجتماعٍ أُنهي — فعلٌ واحد لا يتكرّر. */
    public function test_saving_notes_on_an_ended_session_does_not_end_zoom_again(): void
    {
        config([
            'services.zoom.account_id' => 'acc', 'services.zoom.client_id' => 'cid',
            'services.zoom.client_secret' => 'secret',
        ]);
        Http::fake(['*' => Http::response([], 200)]);

        [$consult, $lawyer] = $this->consult([
            'session' => 'منتهية', 'status' => 'منتهية', 'meet_id' => '81823767754',
            'summary' => 'ملخّصٌ قائم.',
        ]);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/end", ['notes' => 'تدوينٌ متأخّر.'])
            ->assertRedirect();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/status'));
        $this->assertSame('تدوينٌ متأخّر.', $consult->fresh()->session_notes, 'والتدوين يُحفظ');
    }
}
