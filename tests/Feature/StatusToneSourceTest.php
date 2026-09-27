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
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
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
}
