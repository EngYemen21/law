<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\MeetRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * فجوات تكامل Zoom في مسارات الفشل — المسار السعيد مغطّى بـ77 اختباراً قائماً وكلّها خضراء.
 * هذه تُثبت ما لا تكشفه: ما يحدث حين يخفق Zoom أو حين يُنشأ اجتماعان لنفس الموعد.
 */
class ZoomGapsTest extends TestCase
{
    use RefreshDatabase;

    private function configureZoom(): void
    {
        config([
            'services.zoom.account_id' => 'acc',
            'services.zoom.client_id' => 'cid',
            'services.zoom.client_secret' => 'sec',
        ]);
    }

    /** استشارة مرئية وصلت إلى «بانتظار تحديد الموعد» عبر الدورة الطبيعية (تذكرة → تسعير → سداد). */
    private function paidVideoConsult(User $client, string $ticketNo): Consult
    {
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => $ticketNo, 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'status' => 'بانتظار حجز الاستشارة', 'tone' => 'b-amber',
        ]);
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => 'video'])->assertNoContent();
        $consult = $ticket->consults()->latest('id')->firstOrFail();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 450])->assertRedirect();
        ConsultBooking::markPaid($consult->fresh());

        return $consult->fresh();
    }

    /**
     * 🔴 فشل إنشاء اجتماع Zoom يُبتلع صامتاً.
     *
     * الاستشارة تُجدوَل وتُؤكَّد ويُرسَل بريدها بـmeet_id فارغ، ولا يوجد في المنظومة أي
     * استعلام whereNull('meet_id') يستدركها لاحقاً — فالعميل يصل الغرفة ويحصل على 422 أبداً.
     * المطلوب: تسجيل خطأ صريح على مستوى العمل (لا تحذير داخل الخدمة وحده) ليُمكن الاستدراك.
     */
    public function test_failed_zoom_creation_is_reported_not_swallowed(): void
    {
        $this->configureZoom();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/meetings' => Http::response(['message' => 'Zoom down'], 500),
        ]);

        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'أ. سارة القحطاني']);
        $consult = $this->paidVideoConsult($client, 'SB-2026-9101');

        Log::spy();

        $startsAt = now()->addDay()->setTime(11, 30);
        ConsultBooking::schedule($consult, [
            'lawyer_id' => $lawyer->id, 'day' => 'غد', 'time' => '11:30',
            'starts_at' => $startsAt->toDateTimeString(), 'duration' => 30,
        ]);

        $consult->refresh();

        // الضرر الواقع: الاستشارة مجدولة ومؤكّدة بلا اجتماع
        $this->assertSame('جديدة', $consult->status);
        $this->assertNull($consult->meet_id, 'المفترض ألّا يُنشأ اجتماع — Zoom مُعطَّل في هذا الاختبار');

        // المطلوب: خطأ صريح يُمكّن من الاستدراك (اليوم لا يُسجَّل شيء على مستوى العمل)
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'Zoom'))
            ->atLeast()->once();
    }

    /**
     * 🟠 جلسة Zoom **واحدة** لكل دعوة — الحارس نفسه، بعد أن تحرّك موضعه.
     *
     * كان السيناريو: Staff\MeetingController::store يُنشئ اجتماعاً ثم
     * MeetRequestController::confirm يُنشئ آخر ويكتب معرّفه فوق الأول، فيبقى الأول يتيماً
     * في حساب Zoom بتسجيل سحابي مفعّل. تأكيد العميل أُلغي فصار السيناريو مستحيلاً بنيوياً،
     * لكن الحارس انتقل حرفياً إلى MeetInvitation::schedule ويُفحص هنا مباشرةً: نداء ثانٍ
     * على دعوة تحمل اجتماعاً بمعرّف Zoom يجب ألّا يُنشئ جلسة ثانية.
     */
    public function test_scheduling_twice_never_creates_a_second_zoom_meeting(): void
    {
        $this->configureZoom();
        Http::fake([
            'zoom.us/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.zoom.us/v2/users/me/meetings' => Http::sequence()
                ->push(['id' => 111111111, 'join_url' => 'https://z/j/1', 'start_url' => 'https://z/s/1', 'password' => 'p1'], 201)
                ->push(['id' => 222222222, 'join_url' => 'https://z/j/2', 'start_url' => 'https://z/s/2', 'password' => 'p2'], 201),
            'api.zoom.us/v2/meetings/*' => Http::response([], 204),
        ]);

        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $req = \App\Models\MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-ORPHAN', 'service' => 'نزاع',
            'type' => 'استشارة مرئية', 'day' => now()->addDays(2)->format('Y-m-d'), 'time' => '10:00',
            'duration_min' => 60, 'assigned_lawyer_id' => $lawyer->id, 'sent_by' => 'المكتب',
        ]);

        $first = \App\Support\MeetInvitation::schedule($req, $client);
        $second = \App\Support\MeetInvitation::schedule($req->fresh(), $client);

        $this->assertSame($first->id, $second->id, 'أُنشئ اجتماع ثانٍ بدل إعادة استعمال الأول.');
        $this->assertSame('111111111', (string) $second->fresh()->meet_id, 'كُتب معرّف Zoom جديد فوق الأول — الأول يتيم.');
        $this->assertSame(1, \App\Models\Meeting::count());
    }}
