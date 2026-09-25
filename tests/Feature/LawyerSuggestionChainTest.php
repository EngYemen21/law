<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\EscalateUnassignedTicketJob;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketAssignment;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **سلسلة اقتراح المحامي** (قرار المالك 2026-09-25) — دون نقض قرار 2026-09-20 «الإسناد بشريّ».
 *
 * النظام يقترح: مختصٌّ بقسم التذكرة ← وإلّا محامٍ آخر **موسومٌ بأنّه غير مختصّ** ← وإن لم يكن في
 * المكتب محامٍ أصلاً فالإدارة العليا صاحبة الملفّ من لحظة فتحه. والاقتراح يراه الطاقم في شاشتَي
 * «توزيع التذاكر» و«تحويل التذاكر» فيؤكّدونه — لا يُكتب إسنادٌ بلا إنسان ما دام في المكتب محامٍ.
 */
class LawyerSuggestionChainTest extends TestCase
{
    use RefreshDatabase;

    private const DEPT = 'القضايا العمالية';

    private function lawyer(string $dept, string $mode = 'auto'): User
    {
        return User::factory()->create([
            'role' => Role::Lawyer, 'status' => 'active',
            'distribution_mode' => $mode, 'department' => $dept,
        ]);
    }

    private function ticket(string $number = 'SB-2026-7001'): Ticket
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Ticket::create([
            'user_id' => $client->id, 'number' => $number, 'type' => 'نزاع عمالي',
            'department' => self::DEPT, 'status' => 'قيد التحليل', 'tone' => 'b-blue',
        ]);
    }

    private function openTicket(): Ticket
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع عمالي', 'department' => self::DEPT, 'details' => 'تفاصيل الطلب.',
        ])->assertRedirect();

        return Ticket::latest('id')->firstOrFail();
    }

    public function test_a_specialist_is_suggested_when_one_exists(): void
    {
        $this->lawyer('العقارات');
        $specialist = $this->lawyer(self::DEPT);

        $s = TicketAssignment::suggest($this->ticket());

        $this->assertSame($specialist->id, $s->lawyer?->id);
        $this->assertTrue($s->specialist);
        $this->assertStringContainsString('مختصّ بقسم التذكرة', $s->label());
    }

    /** لا مختصّ ⇒ يُقترح محامٍ آخر **موسوماً** — لا يُعرض كأنّه الاختيار الطبيعيّ. */
    public function test_another_lawyer_is_suggested_and_labelled_when_no_specialist_exists(): void
    {
        $outsider = $this->lawyer('العقارات');
        $ticket = $this->ticket();

        $s = TicketAssignment::suggest($ticket);

        $this->assertSame($outsider->id, $s->lawyer?->id);
        $this->assertFalse($s->specialist);
        $this->assertStringContainsString('غير مختصّ', $s->label());
        $this->assertStringContainsString(self::DEPT, $s->label());

        // والواجهة المختصرة بصرامة التخصّص لا تُعيده (السلوك القائم محفوظ)
        $this->assertNull(TicketAssignment::pickLawyer($ticket, requireSpecialty: true));
        $this->assertSame($outsider->id, TicketAssignment::pickLawyer($ticket)?->id);
    }

    /** لا محامي في المكتب أصلاً ⇒ الإدارة العليا صاحبة الملفّ من لحظة فتحه — لا بعد ساعتين. */
    public function test_a_ticket_opened_with_no_lawyer_at_all_goes_to_senior_management(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $ticket = $this->openTicket()->fresh();

        $this->assertSame($admin->id, $ticket->assigned_lawyer_id, 'بقيت التذكرة بلا صاحبٍ ولا محامي في المكتب.');
        $this->assertSame(EscalateUnassignedTicketJob::SENIOR_LABEL, $ticket->assigned_lawyer);
    }

    /**
     * وجود محامٍ واحد — ولو غير مختصّ، ولو في وضع «يدويّ» — يُبقي القرار للإنسان (2026-09-20):
     * لا إسناد ولا تصعيد عند الفتح.
     */
    public function test_any_lawyer_in_the_office_keeps_opening_unassigned(): void
    {
        User::factory()->create(['role' => Role::Admin]);
        $this->lawyer('العقارات', mode: 'manual');

        $ticket = $this->openTicket()->fresh();

        $this->assertNull($ticket->assigned_lawyer_id, 'أُسنِدت التذكرة عند الفتح رغم وجود محامٍ يملك الطاقم إسناده.');
    }

    /** شاشة «توزيع التذاكر» تعرض الاقتراح بوسمه — لغير المسنَدة وحدها. */
    public function test_the_distribute_screen_shows_the_labelled_suggestion(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $outsider = $this->lawyer('العقارات');
        $open = $this->ticket('SB-2026-7002');
        $assigned = $this->ticket('SB-2026-7003');
        $assigned->update(['assigned_lawyer_id' => $outsider->id, 'assigned_lawyer' => $outsider->name]);

        $this->actingAs($admin)->get(route('admin.distribute'))
            ->assertOk()
            ->assertInertia(function ($page) use ($open, $assigned, $outsider) {
                $tickets = collect($page->toArray()['props']['tickets']);
                $row = $tickets->firstWhere('no', $open->number);
                $this->assertSame($outsider->id, $row['suggestion']['lawyerId']);
                $this->assertFalse($row['suggestion']['specialist']);
                $this->assertNull($tickets->firstWhere('no', $assigned->number)['suggestion']);
            });
    }

    /** و«تحويل التذاكر» عند الموظّف كذلك — يؤكّد الاقتراح ولا يُكتب شيءٌ قبله. */
    public function test_the_transfer_desk_shows_the_suggestion_without_assigning(): void
    {
        $this->seed(PermissionSeeder::class);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['تحويل التذاكر'])->get());
        $specialist = $this->lawyer(self::DEPT);
        $ticket = $this->ticket();

        $this->actingAs($employee)->get(route('employee.transfer'))
            ->assertOk()
            ->assertInertia(function ($page) use ($ticket, $specialist) {
                $row = collect($page->toArray()['props']['tickets'])->firstWhere('no', $ticket->number);
                $this->assertSame($specialist->id, $row['suggestion']['lawyerId']);
                $this->assertTrue($row['suggestion']['specialist']);
            });

        $this->assertNull($ticket->fresh()->assigned_lawyer_id, 'الاقتراح كتب إسناداً.');
    }
}
