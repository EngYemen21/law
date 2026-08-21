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
     * 🟠 تأكيد الدعوة يُنشئ اجتماع Zoom ثانياً ويستبدل الأول بلا حذفه.
     *
     * Staff\MeetingController::store ينشئ اجتماعاً، ثم MeetRequestController::confirm ينشئ
     * آخر ويكتب معرّفه فوق الأول — فيبقى الأول يتيماً في حساب Zoom بتسجيل سحابي مفعّل.
     */
    public function test_invite_confirmation_does_not_leave_an_orphan_zoom_meeting(): void
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
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($admin)->post(route('admin.meetings.store'), [
            'title' => 'اجتماع مراجعة العقد', 'type' => 'اجتماع مع عميل', 'priority' => 'عالية',
            'dur' => '45 دقيقة', 'client_id' => $client->id, 'lawyer_id' => $lawyer->id,
            'day' => '2026-07-08', 'time' => '10:00',
        ])->assertRedirect();

        $req = MeetRequest::firstOrFail();
        $this->actingAs($client)->post(route('meetreqs.confirm', $req))->assertRedirect();

        $creates = 0;
        Http::assertSent(function ($request) use (&$creates) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/users/me/meetings')) {
                $creates++;
            }

            return true;
        });

        // إمّا اجتماع واحد لا اثنان، وإمّا حذف الأول صراحةً — لا يُترك يتيماً
        if ($creates > 1) {
            Http::assertSent(
                fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'meetings/111111111'),
            );
        }

        $this->assertLessThanOrEqual(1, $creates, 'أُنشئ اجتماعان لنفس الدعوة والأول لم يُحذف.');
    }
}
