<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
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
}
