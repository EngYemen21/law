<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * **العميل يقرأ تسميته لا حالة المكتب الداخليّة** (قرار المالك 2026-09-14، تدقيق اللوحات ٩–١١).
 *
 * كانت `TicketStatus::clientLabel()` معرَّفةً بلا أيّ نداء، فيقرأ العميل «بانتظار اعتماد الإدارة
 * للملخّص» و«بانتظار ملخّص الجلسة»، وتعرض قائمته المنسدلة كلّ حالات الكتالوج بما فيها القديمة،
 * وتسقط الحالات الجديدة من تبويبَي «التحليل» و«الرأي القانوني» فلا يجدها إلا في «الكل».
 */
class ClientTicketStatusLabelsTest extends TestCase
{
    use RefreshDatabase;

    private function ticketFor(User $client, string $status): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-CL-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => $status, 'tone' => TicketJourney::toneFor($status),
        ]);
    }

    /** @return array<string, string> رقم التذكرة ⇐ الحالة المعروضة */
    private function statusesByNo(Collection $tickets): array
    {
        return $tickets->mapWithKeys(fn ($t) => [$t['no'] => $t['status']])->all();
    }

    public function test_client_list_shows_client_labels_phases_and_matching_counts(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyerApproval = $this->ticketFor($client, 'بانتظار اعتماد المستشار');
        $adminApproval = $this->ticketFor($client, 'بانتظار اعتماد الإدارة للملخّص');
        $schedule = $this->ticketFor($client, 'بانتظار تحديد الموعد');
        $sessionSummary = $this->ticketFor($client, 'بانتظار ملخّص الجلسة');

        $this->actingAs($client)->get(route('tickets'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('tickets', function (Collection $tickets) use ($lawyerApproval, $adminApproval, $schedule, $sessionSummary) {
                    $byNo = $this->statusesByNo($tickets);
                    $this->assertSame('قيد إعداد الرأي القانوني', $byNo[$lawyerApproval->number]);
                    $this->assertSame('قيد إعداد الرأي القانوني', $byNo[$adminApproval->number]);
                    $this->assertSame('بانتظار تحديد الموعد', $byNo[$schedule->number]);
                    $this->assertSame('جارٍ إعداد ملخّص الجلسة', $byNo[$sessionSummary->number]);

                    $phases = $tickets->mapWithKeys(fn ($t) => [$t['no'] => $t['phase'] ?? null])->all();
                    $this->assertSame('analysis', $phases[$lawyerApproval->number]);
                    $this->assertSame('analysis', $phases[$adminApproval->number]);
                    $this->assertSame('opinion', $phases[$schedule->number]);
                    $this->assertSame('opinion', $phases[$sessionSummary->number]);

                    return true;
                })
                // العدّاد يعدّ ما يعرضه تبويبه
                ->where('counts.inAnalysis', 2)
                ->where('counts.inOpinion', 2)
                ->where('availableStatuses', function (Collection $statuses) {
                    $this->assertContains('قيد إعداد الرأي القانوني', $statuses->all());
                    $this->assertContains('جارٍ إعداد ملخّص الجلسة', $statuses->all());
                    $this->assertNotContains('بانتظار اعتماد الإدارة للملخّص', $statuses->all());
                    $this->assertNotContains('بانتظار ملخّص الجلسة', $statuses->all());
                    $this->assertNotContains('بانتظار اعتماد المستشار', $statuses->all());
                    // القديمة لا تُعرض للعميل خياراً
                    $this->assertNotContains('قيد التنفيذ', $statuses->all());
                    $this->assertNotContains('بانتظار اعتماد النتيجة', $statuses->all());
                    // والقائمة بلا تكرار: حالتا الاعتماد تشتركان في تسميةٍ واحدة
                    $this->assertSame($statuses->unique()->values()->all(), $statuses->values()->all());

                    return true;
                }));
    }

    public function test_client_chat_and_dashboard_use_client_labels(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketFor($client, 'بانتظار اعتماد الإدارة للملخّص');

        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('ticket.status', 'قيد إعداد الرأي القانوني'));

        $this->actingAs($client)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('activeTickets.0.status', 'قيد إعداد الرأي القانوني')
                ->where('lastTicket.status', 'قيد إعداد الرأي القانوني')
                // المرحلة تُحسب من الحالة الحقيقيّة لا من التسمية
                ->where('lastTicket.step', TicketJourney::indexOf('بانتظار اعتماد الإدارة للملخّص')));
    }

    /** القناة مشتركة مع الطاقم: `status` يبقى داخليّاً، وتُضاف تسمية العميل بجواره. */
    public function test_status_broadcast_carries_client_label_beside_the_raw_status(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketFor($client, 'بانتظار ملخّص الجلسة');

        $payload = (new TicketStatusBroadcast($ticket))->broadcastWith();

        $this->assertSame('بانتظار ملخّص الجلسة', $payload['status']);
        $this->assertSame('جارٍ إعداد ملخّص الجلسة', $payload['clientStatus'] ?? null);
    }

    /** والطاقم لا يتغيّر عليه شيء — يرى الحالة الداخليّة كما هي. */
    public function test_staff_still_see_the_internal_status(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $ticket = $this->ticketFor($client, 'بانتظار اعتماد الإدارة للملخّص');

        $this->actingAs($employee)->get(route('employee.tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('ticket.status', 'بانتظار اعتماد الإدارة للملخّص'));
    }

    /** الواجهة تبني التبويبين على مجموعة الخادم، والمحادثة تقرأ تسمية البثّ. */
    public function test_client_screens_read_the_server_phase_and_client_label(): void
    {
        $list = (string) file_get_contents(resource_path('js/pages/tickets.tsx'));
        $this->assertStringContainsString("t.phase === 'analysis'", $list);
        $this->assertStringContainsString("t.phase === 'opinion'", $list);
        $this->assertStringNotContainsString("['الرأي القانوني', 'بانتظار حجز الاستشارة', 'موعد مؤكد']", $list);

        $chat = (string) file_get_contents(resource_path('js/pages/ticketchat.tsx'));
        $this->assertStringContainsString('clientStatus', $chat);

        // مسار الرحلة لا يُشتقّ من التسمية: المرحلة `step` من الخادم بالحالة الداخليّة، فلا يرتدّ إلى الصفر
        $this->assertStringContainsString('cur={status.step}', $chat);
        $client = User::factory()->create(['role' => Role::Client]);
        foreach (['محالة للقسم القانوني' => 2, 'بانتظار ملخّص الجلسة' => 5] as $internal => $stage) {
            $this->assertSame($stage, (new TicketStatusBroadcast($this->ticketFor($client, $internal)))->broadcastWith()['step'], $internal);
        }
    }
}
