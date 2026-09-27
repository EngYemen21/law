<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\HearingStatus;
use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Enums\Role;
use App\Models\CaseHearing;
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
}
