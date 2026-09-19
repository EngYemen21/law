<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Models\User;
use App\Support\ConsultAppointments;
use App\Support\MeetInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * فجوات تكامل Zoom في مسارات الفشل — المسار السعيد مغطّى باختباراتٍ قائمة.
 * هذه تُثبت ما لا تكشفه: ما يحدث حين يخفق Zoom أو حين يُنشأ اجتماعان لنفس الموعد.
 */
class ZoomGapsTest extends TestCase
{
    use BuildsConsultJourney;
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
        $ticket = $this->ticketWithApprovedOpinion($client, [
            'number' => $ticketNo, 'department' => 'القسم التجاري', 'status' => 'بانتظار حجز الاستشارة',
        ]);

        return $this->requestPricedAndPaid($client, $ticket, 'video');
    }

    /**
     * 🔴 فشل إنشاء اجتماع Zoom يُبتلع صامتاً.
     *
     * الاستشارة تُنشر بـmeet_id فارغ، ولا يوجد في المنظومة أي استعلام يستدركها لاحقاً.
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
        ConsultAppointments::publish($consult, $this->journeyAdmin(), [
            'lawyer_id' => $lawyer->id, 'date' => $startsAt->toDateString(), 'time' => '11:30',
        ]);

        $consult->refresh();

        // الضرر الواقع: الاستشارة منشورة بلا اجتماع
        $this->assertSame('جديدة', $consult->status);
        $this->assertNull($consult->meet_id, 'المفترض ألّا يُنشأ اجتماع — Zoom مُعطَّل في هذا الاختبار');

        // المطلوب: خطأ صريح يُمكّن من الاستدراك
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'Zoom'))
            ->atLeast()->once();
    }

    /**
     * 🟠 جلسة Zoom **واحدة** لكل دعوة — الحارس نفسه، بعد أن تحرّك موضعه.
     *
     * تأكيد العميل أُلغي فصار السيناريو القديم مستحيلاً بنيوياً، لكن الحارس انتقل حرفياً إلى
     * MeetInvitation::schedule ويُفحص هنا مباشرةً: نداء ثانٍ على دعوة تحمل اجتماعاً بمعرّف Zoom
     * يجب ألّا يُنشئ جلسة ثانية.
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
        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-ORPHAN', 'service' => 'نزاع',
            'type' => 'استشارة مرئية', 'day' => now()->addDays(2)->format('Y-m-d'), 'time' => '10:00',
            'duration_min' => 60, 'assigned_lawyer_id' => $lawyer->id, 'sent_by' => 'المكتب',
        ]);

        $first = MeetInvitation::schedule($req, $client);
        $second = MeetInvitation::schedule($req->fresh(), $client);

        $this->assertSame($first->id, $second->id, 'أُنشئ اجتماع ثانٍ بدل إعادة استعمال الأول.');
        $this->assertSame('111111111', (string) $second->fresh()->meet_id, 'كُتب معرّف Zoom جديد فوق الأول — الأول يتيم.');
        $this->assertSame(1, Meeting::count());
    }
}
