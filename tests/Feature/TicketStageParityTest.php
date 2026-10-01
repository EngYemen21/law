<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\TicketStatusBroadcast;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **مرحلة «مسار المعالجة» من الخادم وحده.**
 *
 * كانت `tktStage()` في `resources/js/lib/chat.ts` خريطةً ثانيةً تشتقّ المرحلة من نصّ الحالة العربيّ
 * (وتسميات العميل)، يُزامنها هذا الاختبار يدويّاً مع `TicketJourney::indexOf`. صار الخادم يرسل
 * `step` في بطاقتَي العميل والطاقم وفي بثّ الحالة، وحُذفت الخريطة — فلا نسختان تتباعدان.
 */
class TicketStageParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_frontend_no_longer_derives_the_stage_from_status_text(): void
    {
        $src = (string) file_get_contents(base_path('resources/js/lib/chat.ts'));
        $this->assertStringNotContainsString('tktStage', $src);

        foreach (['pages/ticketchat.tsx', 'pages/employee/ticketchat.tsx', 'pages/lawyer/ticketchat.tsx'] as $page) {
            $this->assertStringNotContainsString('tktStage', (string) file_get_contents(resource_path('js/'.$page)), $page);
        }
    }

    /** كلّ حالةٍ يقبلها الخادم: المرحلة نفسها في بطاقة العميل وبطاقة الطاقم والبثّ. */
    public function test_every_status_carries_its_server_stage_everywhere(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        foreach (TicketJourney::statuses() as $i => $status) {
            $ticket = Ticket::create(['user_id' => $client->id, 'number' => 'SB-STEP-'.$i, 'type' => 'نزاع', 'status' => $status, 'tone' => 'b-blue']);
            $expected = TicketJourney::indexOf($status);

            $this->assertSame($expected, $ticket->toCard()['step'], "بطاقة العميل: {$status}");
            $this->assertSame($expected, $ticket->toEmployeeCard()['step'], "بطاقة الطاقم: {$status}");
            $this->assertSame($expected, (new TicketStatusBroadcast($ticket))->broadcastWith()['step'], "البثّ: {$status}");
        }
    }

    public function test_stage_count_matches_flow_line_labels(): void
    {
        $src = (string) file_get_contents(base_path('resources/js/lib/chat.ts'));
        $this->assertSame(1, preg_match('/export const TKT_LIFE = \[(.*?)\]/s', $src, $m));
        $labels = preg_match_all("/'[^']+'/u", $m[1]);

        $this->assertSame(count(TicketJourney::STAGES), $labels, 'عدد مراحل شريط الرحلة يخالف STAGES');
    }
}
