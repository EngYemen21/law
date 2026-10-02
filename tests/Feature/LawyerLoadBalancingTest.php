<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerAvailability;
use App\Support\TicketAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **المحامي الجديد يأخذ حصّته** (قرار المالك 2026-10-02).
 *
 * ثبت في التوزيع: محامٍ جديد «يغطّي كلّ الأقسام» لا يصله شيء — إسناد الاستشارة الآليّ يقدّم الأكثر إنجازاً
 * (فأخذ الأقدم الاستشارات كلّها وحِمله 49)، واقتراح التذكرة يعدّ التذاكر وحدها ويُعطي الدفعة كلّها لواحد.
 * الآن الحِمل الكامل (`LawyerWorkload`) أوّلاً في الموضعين، والإنجاز يفصل بين المتساوين، والدفعة تتوزّع.
 */
class LawyerLoadBalancingTest extends TestCase
{
    use RefreshDatabase;

    private const DEPT = 'القضايا العمالية';

    private function lawyer(string $dept = 'كل الأقسام'): User
    {
        return User::factory()->create([
            'role' => Role::Lawyer, 'status' => 'active', 'distribution_mode' => 'auto',
            'department' => $dept, 'covers_all_departments' => $dept === 'كل الأقسام',
        ]);
    }

    private function ticket(?User $lawyer = null, string $status = 'قيد التحليل'): Ticket
    {
        return Ticket::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'number' => 'SB-LB-'.uniqid(),
            'type' => 'نزاع عمالي', 'department' => self::DEPT, 'status' => $status, 'tone' => 'b-blue',
            'assigned_lawyer_id' => $lawyer?->id,
        ]);
    }

    private function activeCase(User $lawyer): void
    {
        LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'assigned_lawyer_id' => $lawyer->id,
            'number' => 'CASE-LB-'.uniqid(), 'type' => 'نزاع', 'status' => 'قيد التحضير', 'tone' => 'b-blue',
        ]);
    }

    // ── (أ) إسناد الاستشارة الآليّ ──

    public function test_a_new_lawyer_comes_before_a_loaded_experienced_one(): void
    {
        $senior = $this->lawyer();
        foreach (range(1, 3) as $i) {
            $this->ticket($senior, 'مغلقة'); // إنجاز
        }
        $this->ticket($senior);              // حِملٌ مفتوح
        $newcomer = $this->lawyer();

        $ranked = LawyerAvailability::rankedSpecialists(self::DEPT, null, now()->addDay()->toDateString());

        $this->assertSame([$newcomer->id, $senior->id], array_column($ranked, 'id'));
        $this->assertSame(0, $ranked[0]['load']);
    }

    public function test_the_track_record_breaks_a_tie_in_load(): void
    {
        $newcomer = $this->lawyer();
        $senior = $this->lawyer();
        $this->ticket($senior, 'مغلقة');

        $ranked = LawyerAvailability::rankedSpecialists(self::DEPT, null, now()->addDay()->toDateString());

        $this->assertSame([$senior->id, $newcomer->id], array_column($ranked, 'id'), 'حِملٌ متساوٍ (صفر) ⇒ الأكثر إنجازاً أوّلاً');
    }

    // ── (ب) اقتراح التذكرة بالحِمل الكامل ──

    public function test_the_ticket_suggestion_weighs_cases_not_only_tickets(): void
    {
        $withCase = $this->lawyer();
        $this->activeCase($withCase);           // حِمل 2 بلا تذكرة واحدة
        $withTicket = $this->lawyer();
        $this->ticket($withTicket);             // حِمل 1

        $this->assertSame($withTicket->id, TicketAssignment::suggest($this->ticket())->lawyer?->id);
    }

    public function test_a_covers_all_newcomer_is_suggested_over_a_busy_specialist(): void
    {
        $specialist = $this->lawyer(self::DEPT);
        $this->ticket($specialist);
        $newcomer = $this->lawyer();

        $s = TicketAssignment::suggest($this->ticket());

        $this->assertSame($newcomer->id, $s->lawyer?->id);
        $this->assertTrue($s->specialist, '«يغطّي كلّ الأقسام» مختصٌّ بكلّ قسم');
    }

    // ── (ج) الدفعة تتوزّع ──

    public function test_a_batch_is_spread_across_lawyers(): void
    {
        $a = $this->lawyer();
        $b = $this->lawyer();
        $tickets = [$this->ticket(), $this->ticket(), $this->ticket(), $this->ticket()];

        $picked = array_map(fn ($s) => $s->lawyer?->id, array_values(TicketAssignment::suggestMany($tickets)));

        $this->assertSame([$a->id, $b->id, $a->id, $b->id], $picked);
    }
}
