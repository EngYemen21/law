<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * **الموعد الذي اقترحه موظّف لا يصل العميلَ منه أثر قبل اعتماد الإدارة** (تدقيق اللوحات ١٢–١٦).
 *
 * كانت الحمولات الرئيسة تستثني «بانتظار الاعتماد»، وبقيت أطرافٌ تُفشيه: شارة التقويم تعدّه،
 * و«الاستشارات المرئية» تعرض طلباً لم يُنشر موعده، وقائمة ترشيح التقويم تسمّي الحالة الداخليّة،
 * وتقرير الاستشارة يطبعها، والمكان المقترح يظهر في بطاقة العميل.
 */
class PendingAppointmentClientVisibilityTest extends TestCase
{
    use BuildsConsultJourney;
    use RefreshDatabase;

    /** @return array{0: User, 1: Consult, 2: User} */
    private function proposedConsult(string $type = 'office'): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'محمد بن خالد']);
        $ticket = $this->ticketWithApprovedOpinion($client);
        $consult = $this->requestPricedAndPaid($client, $ticket, $type);

        $this->employeeProposes($consult, [
            'date' => now()->addDays(3)->format('Y-m-d'),
            'time' => '11:00',
            'lawyer_id' => $lawyer->id,
            'type' => $type,
        ])->assertSuccessful();

        $consult = $consult->fresh();
        $this->assertSame('بانتظار اعتماد الموعد', $consult->status);
        $this->assertSame('بانتظار الاعتماد', $consult->appointment?->status);

        return [$client, $consult, $lawyer];
    }

    public function test_calendar_nav_badge_does_not_count_an_unapproved_appointment(): void
    {
        [$client] = $this->proposedConsult();

        $this->actingAs($client)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('navBadges./calendar', 0));
    }

    /** الاجتماعات لا تحمل استشاراتٍ أصلاً (جرد التبويبات 2026-10-04) — والطلب المسدَّد في «استشاراتي» بانتظار موعده. */
    public function test_video_consults_on_the_meetings_page_exclude_pre_session_requests(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketWithApprovedOpinion($client);
        $consult = $this->requestPricedAndPaid($client, $ticket, 'video');
        $this->assertSame('مرئية', $consult->channel);
        $this->assertSame('بانتظار تحديد الموعد', $consult->status);

        $this->actingAs($client)->get(route('meetings'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->missing('videoConsults')->missing('stats.videoConsultsCount'));

        $this->actingAs($client)->get(route('myconsults'))
            ->assertInertia(fn ($p) => $p->has('consults', 1)->where('consults.0.ref', $consult->ref)->where('stats.pendingBooking', 1));
    }

    public function test_client_calendar_status_filter_uses_client_labels(): void
    {
        [$client, $consult] = $this->proposedConsult();

        $this->actingAs($client)->get(route('calendar'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('statuses', function (Collection $statuses) {
                $this->assertNotContains('بانتظار اعتماد الموعد', $statuses->all());
                $this->assertContains('بانتظار تحديد الموعد', $statuses->all());

                return true;
            }));

        // والترشيح بالتسمية يجد الطلب نفسه — القائمة والنتيجة متّسقتان
        $this->actingAs($client)->get(route('calendar', ['status' => 'بانتظار تحديد الموعد', 'kind' => 'consult']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('meta.total', 1));
    }

    public function test_consult_report_prints_the_client_label_for_the_client(): void
    {
        [$client, $consult] = $this->proposedConsult();

        $this->actingAs($client);
        $doc = ConsultReport::doc($consult->fresh(), $client->name);
        $rows = collect($doc['approval']['rows'])->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);

        $this->assertSame('بانتظار تحديد الموعد', $rows['حالة الاستشارة']);

        // والمكان المقترح لا يُطبع للعميل قبل الاعتماد
        $cells = collect($doc['blocks'][0]['cellRows'])->flatten(1)->mapWithKeys(fn ($c) => [$c[0] => $c[1]]);
        $this->assertSame('—', $cells['المكان']);
    }

    /** «الإجراء القادم» لا يقول «بانتظار اعتماد المستشار» بعد أن اعتمده. */
    public function test_report_next_step_does_not_claim_lawyer_approval_is_pending_after_it(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-RPT-'.uniqid(), 'subject' => 'نزاع',
            'channel' => 'مرئية', 'lawyer' => 'أ. سارة', 'status' => 'قيد الاستشارة', 'session' => 'منتهية',
            'priced_at' => now(), 'paid_at' => now(), 'summary' => 'ملخّص الجلسة',
            'summary_lawyer_approved_at' => now(),
        ]);

        $doc = ConsultReport::doc($consult->fresh(), $client->name);
        $next = collect($doc['blocks'])->first(fn ($b) => ($b['title'] ?? '') === '٦. الإجراء القادم');

        $this->assertSame(['بانتظار اعتماد ملخص الاستشارة'], $next['chips']);
    }

    public function test_proposed_place_is_hidden_from_the_client_but_shown_to_staff(): void
    {
        [$client, $consult] = $this->proposedConsult('office');
        $consult->load('appointment');

        $this->assertSame('', $consult->toClientCard()['place']);
        $this->assertNotSame('', $consult->toCard()['place'], 'الطاقم يرى المكان المقترح');

        $this->actingAs($client)->get(route('myconsults'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('consults.0.place', ''));

        // بعد اعتماد الإدارة يظهر المكان للعميل
        $this->adminApprovesAppointment($consult)->assertRedirect();
        $this->assertNotSame('', $consult->fresh()->load('appointment')->toClientCard()['place']);
        $this->assertInstanceOf(Ticket::class, $consult->fresh()->ticket);
    }
}
