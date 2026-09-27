<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\CaseStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\ExecutionStatus;
use App\Domain\Journey\Enums\HearingStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\JourneyTransition;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ExecFlow;
use App\Support\TicketJourney;
use App\Support\TimelineCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **لون الحالة من مصدرٍ واحد في الخادم** (خطّة «إزالة التعارض» — المرحلة ٢). كلّ حمولةٍ تحمل
 * `tone` محسوباً من الـEnum، والواجهة تقرؤه ولا تعيد اشتقاقه — كانت خرائط الواجهة تنجرف.
 */
class StatusToneSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_approvals_screen_colours_a_track_as_the_enum_does(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        foreach ([TicketOutcomeTrack::Execution, TicketOutcomeTrack::Consultation] as $track) {
            Ticket::create([
                'user_id' => $client->id, 'number' => 'TK-TONE-'.uniqid(), 'type' => 'نزاع', 'status' => 'الرأي القانوني',
                'proposed_track' => $track->value, 'proposed_track_reason' => 'تسبيبٌ كافٍ لاقتراح هذا المسار للملف.',
                'proposed_by_id' => $lawyer->id, 'proposed_at' => now(),
            ]);
        }

        $this->actingAs($admin)->get(route('admin.approvals'))->assertInertia(fn ($p) => $p
            ->where('ticketTrackProposals', function ($rows) {
                foreach ($rows as $row) {
                    // كانت الشاشة تلوّن التنفيذ أزرق والاستشارة كهرمانيّاً — عكس الـEnum
                    if ($row['proposedTrackTone'] !== TicketOutcomeTrack::from($row['proposedTrack'])->tone()) {
                        return false;
                    }
                }

                return count($rows) === 2;
            }));

        $this->assertStringNotContainsString(
            'const trackTone',
            (string) file_get_contents(resource_path('js/pages/admin/approvals.tsx')),
            'عادت خريطة ألوانٍ محلّيّة للمسارات'
        );
    }

    /** التقويم الزمنيّ يلوّن الجلسة القضائيّة كشاشة الجلسات — كانت كلّ غير فائتةٍ كهرمانيّة. */
    public function test_the_timeline_colours_a_hearing_as_its_status(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-TONE-'.uniqid(), 'type' => 'نزاع', 'status' => 'منظورة',
            'tone' => 'b-blue', 'update_text' => '—',
        ]);

        $expect = [];
        foreach ([
            [HearingStatus::Held, now()->subDay(), 'b-green'],
            [HearingStatus::Cancelled, now()->addDay(), 'b-grey'],
            [HearingStatus::Scheduled, now()->addDay(), 'b-blue'],
            [HearingStatus::Scheduled, now()->subDays(2), 'b-red'], // فائتة
        ] as [$status, $at, $tone]) {
            $h = CaseHearing::create([
                'case_id' => $case->id, 'title' => 'جلسة', 'day' => $at->toDateString(), 'time' => $at->format('H:i'),
                'starts_at' => $at, 'status' => $status->value, 'duration_min' => 45,
            ]);
            $expect[$h->id] = $tone;
        }

        $rows = collect(array_map(fn ($id) => (object) ['kind' => 'hearing', 'model_id' => $id], array_keys($expect)));
        $cards = TimelineCard::hydrate($rows, $client);

        $this->assertSame(array_values($expect), array_column($cards, 'statusTone'));
    }

    /** **الحارس:** لكلّ حالةٍ في كلّ Enum لونٌ من لوحة الشارات — حالةٌ جديدة بلا لون تُسقط الاختبار. */
    public function test_every_status_of_every_enum_has_a_badge_tone(): void
    {
        $palette = ['b-green', 'b-amber', 'b-red', 'b-blue', 'b-grey', 'b-cyan', 'b-purple'];

        foreach ([ConsultStatus::class, SessionState::class, CaseStatus::class, ExecutionStatus::class,
            InvoiceStatus::class, HearingStatus::class, TicketOutcomeTrack::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertContains($case->tone(), $palette, $enum.'::'.$case->name);
            }
        }
    }

    /** بطاقتا الاستشارة تحملان لونَي الشارتين وعلمَي الفوت بمعنىً واحد. */
    public function test_consult_cards_carry_tones_and_the_two_missed_flags(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $make = fn (array $a) => Consult::create(array_merge([
            'user_id' => $client->id, 'ref' => 'CN-TONE-'.uniqid(), 'subject' => 'نزاع', 'type' => 'استشارة',
            'channel' => 'مرئية', 'status' => ConsultStatus::ReferredToLawyer->value,
            'session' => SessionState::Waiting->value, 'lawyer' => 'مستشار', 'starts_at' => now()->addHours(3),
        ], $a));

        $missed = $make(['starts_at' => now()->subHours(3)]);
        $notHeld = $make(['status' => ConsultStatus::NoShow->value, 'session' => SessionState::NotHeld->value, 'starts_at' => now()->subDay()]);
        $ended = $make(['status' => ConsultStatus::Ended->value, 'session' => SessionState::Ended->value]);

        foreach ([$missed, $notHeld, $ended] as $c) {
            foreach ([$c->toCard(), $c->toClientCard()] as $card) {
                $this->assertSame(ConsultStatus::from($c->status)->tone(), $card['tone']);
                $this->assertSame(SessionState::from($c->session)->tone(), $card['sessionTone']);
                // المعنى نفسه في البطاقتين — كانت بطاقة العميل تضمّ «لم تُعقد» إلى `missed`
                $this->assertSame($c->isMissed(), $card['missed']);
                $this->assertSame($c->session === SessionState::NotHeld->value, $card['notHeld']);
            }
        }

        $this->assertTrue($missed->toClientCard()['missed']);
        $this->assertFalse($notHeld->toClientCard()['missed']);
        $this->assertTrue($notHeld->toClientCard()['notHeld']);
        $this->assertSame('b-green', $ended->toCard()['sessionTone'], 'المنتهية خضراء (قرار المالك)');
        $this->assertSame('b-blue', SessionState::Waiting->tone(), 'المنتظرة زرقاء في كلّ مكان (قرار المالك)');
    }

    /** لا خريطة ألوانٍ للاستشارة في الواجهة — `cTone` حُذفت، والشاشات تقرأ `tone` من البطاقة. */
    public function test_no_frontend_consult_tone_map_remains(): void
    {
        $this->assertStringNotContainsString('export function cTone', (string) file_get_contents(resource_path('js/lib/employee-data.ts')));
    }

    /** التقويمات الثلاثة تأخذ لون الحدث من الخادم — كانت الاستشارة تُلوَّن بكتالوج جلسات المحاكم. */
    public function test_calendars_send_the_event_tone(): void
    {
        foreach (['Lawyer', 'Employee'] as $who) {
            $src = (string) file_get_contents(app_path("Http/Controllers/{$who}/CalendarController.php"));
            foreach (['toneForHearing', 'toneForMeeting', 'toneForConsult'] as $fn) {
                $this->assertStringContainsString("EventStatus::{$fn}(", $src, "{$who}: {$fn}");
            }
        }

        foreach (['js/lib/calendar-ui.tsx', 'js/pages/admin/calendar.tsx'] as $f) {
            $this->assertStringContainsString('statusTone: e.statusTone', (string) file_get_contents(resource_path($f)), $f);
        }
    }

    /** بطاقة التنفيذ تحمل لون مرحلتها الفعّالة — كانت الواجهة تنسخ معادلة `ExecFlow::tone` (`execTone`). */
    public function test_the_execution_card_carries_its_stage_tone(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        foreach ([[2, ExecutionStatus::InProgress], [7, ExecutionStatus::InProgress], [null, ExecutionStatus::InProgress], [null, ExecutionStatus::Closed]] as [$stage, $status]) {
            $e = Execution::create([
                'user_id' => $client->id, 'number' => 'EX-TONE-'.uniqid(), 'subject' => 'سند', 'stage' => $stage, 'status' => $status->value,
            ]);

            $this->assertSame(ExecFlow::tone($e->effectiveStage()), $e->toFlowCard()['tone']);
        }

        $this->assertStringNotContainsString('export function execTone', (string) file_get_contents(resource_path('js/lib/exec-flow.ts')));
    }

    /** **العمود المخزَّن لا يُقرأ:** صفٌّ قديم بلونٍ غير لون حالته يُعرض بلون حالته في كلّ حمولة. */
    public function test_a_stale_stored_tone_never_reaches_a_payload(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = Ticket::create(['user_id' => $client->id, 'number' => 'SB-STALE-'.uniqid(), 'type' => 'نزاع', 'status' => 'مغلقة', 'tone' => 'b-purple']);
        $case = LegalCase::create(['user_id' => $client->id, 'number' => 'CASE-STALE-'.uniqid(), 'type' => 'نزاع', 'status' => 'صدر الحكم', 'tone' => 'b-purple', 'update_text' => '—']);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EX-STALE-'.uniqid(), 'subject' => 'سند', 'stage' => 7, 'status' => ExecutionStatus::InProgress->value, 'tone' => 'b-purple']);

        $this->assertSame(TicketJourney::toneFor('مغلقة'), $ticket->fresh()->tone);
        $this->assertSame($ticket->fresh()->tone, $ticket->fresh()->toEmployeeCard()['tone']);
        $this->assertSame(CaseStatus::Judged->tone(), $case->fresh()->toCard()['tone']);
        $this->assertSame(ExecFlow::tone(7), $exec->fresh()->tone);
    }

    /** سجلّ الانتقالات يلوّن كلّ حالةٍ بكتالوج نوعها — كان كتالوج التذاكر للجميع. */
    public function test_the_journey_log_colours_each_entity_by_its_own_catalogue(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $rows = [
            ['LegalCase', 'منظورة', 'صدر الحكم', CaseStatus::Judged->tone()],
            ['Consult', SessionState::Waiting->value, SessionState::NotHeld->value, SessionState::NotHeld->tone()],
            ['Consult', ConsultStatus::AwaitingPayment->value, ConsultStatus::Cancelled->value, ConsultStatus::Cancelled->tone()],
            ['Invoice', InvoiceStatus::Due->value, InvoiceStatus::Paid->value, InvoiceStatus::Paid->tone()],
            ['Execution', ExecutionStatus::InProgress->value, ExecutionStatus::Closed->value, ExecutionStatus::Closed->tone()],
        ];
        foreach ($rows as $i => [$type, $from, $to]) {
            JourneyTransition::create(['entity_type' => $type, 'entity_id' => $i + 1, 'transition' => 't', 'from_state' => $from, 'to_state' => $to]);
        }

        $this->actingAs($admin)->get(route('admin.journey-transitions'))->assertInertia(fn ($p) => $p
            ->where('transitions.data', function ($data) use ($rows) {
                $toneById = collect($data)->mapWithKeys(fn ($r) => [$r['entityId'] => $r['toTone']]);

                foreach ($rows as $i => [, , , $tone]) {
                    if (($toneById[$i + 1] ?? null) !== $tone) {
                        return false;
                    }
                }

                return true;
            }));
    }
}
