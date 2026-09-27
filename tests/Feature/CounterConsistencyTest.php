<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **رقمٌ واحد لكلّ مقياس** — الشارة واللوحة والقائمة تقرأ نطاقاً واحداً من النموذج
 * (`Ticket::open`، `LegalCase::active`، `Invoice::owedByClient`/`overdue`)، فلا يرى العميل
 * «٣ فواتير» على الشارة و«٢» في صفحته، ولا يختلف عدّاد المحامي عن قائمة التذاكر.
 */
class CounterConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
    }

    private function ticket(string $status): Ticket
    {
        return Ticket::create([
            'user_id' => $this->client->id, 'assigned_lawyer_id' => $this->lawyer->id,
            'number' => 'SB-CC-'.uniqid(), 'type' => 'نزاع',
            'status' => $status, 'tone' => TicketJourney::toneFor($status),
        ]);
    }

    private function legalCase(string $status): LegalCase
    {
        return LegalCase::create([
            'user_id' => $this->client->id, 'assigned_lawyer_id' => $this->lawyer->id,
            'number' => 'CASE-CC-'.uniqid(), 'type' => 'نزاع تجاري', 'status' => $status,
            'tone' => 'b-blue', 'update_text' => '—',
        ]);
    }

    private function invoice(InvoiceStatus $status, bool $paid = false, ?string $dueAt = null): Invoice
    {
        return Invoice::create([
            'user_id' => $this->client->id, 'number' => 'INV-CC-'.uniqid(), 'description' => 'اختبار',
            'amount' => 1000, 'status' => $status->value, 'tone' => $status->tone(),
            'due_at' => $dueAt, 'due_label' => '—', 'paid' => $paid,
        ]);
    }

    public function test_the_client_invoice_badge_dashboard_and_list_agree(): void
    {
        $this->invoice(InvoiceStatus::Due);                                   // يُطالَب بها
        $this->invoice(InvoiceStatus::Due, dueAt: now()->subDays(3)->toDateString()); // يُطالَب بها ومتأخّرة
        $this->invoice(InvoiceStatus::Draft);                                 // لم تصدر بعد
        $this->invoice(InvoiceStatus::Cancelled);
        $this->invoice(InvoiceStatus::WrittenOff, dueAt: now()->subDays(9)->toDateString());
        $this->invoice(InvoiceStatus::Paid, paid: true);

        $this->actingAs($this->client)->get('/dashboard')->assertInertia(fn ($p) => $p
            ->where('navBadges./invoices', 2)
            ->where('counts.dueInv', 2)
            ->where('counts.overdueInv', 1));

        $this->actingAs($this->client)->get('/invoices')->assertInertia(fn ($p) => $p
            ->where('invoices', fn ($cards) => collect($cards)->where('receivable', true)->count() === 2));
    }

    public function test_a_written_off_invoice_is_never_overdue(): void
    {
        $inv = $this->invoice(InvoiceStatus::WrittenOff, dueAt: now()->subDays(9)->toDateString());

        $this->assertFalse($inv->isOverdue());
        $this->assertSame('دين معدوم', $inv->liveStatus()[0]);
    }

    public function test_the_admin_overdue_count_matches_the_invoice_verdict(): void
    {
        $this->invoice(InvoiceStatus::Due, dueAt: now()->subDays(3)->toDateString());
        $this->invoice(InvoiceStatus::Due, dueAt: now()->toDateString());          // يوم الاستحقاق ليس تأخّراً
        $this->invoice(InvoiceStatus::Cancelled, dueAt: now()->subDays(3)->toDateString());
        $this->invoice(InvoiceStatus::WrittenOff, dueAt: now()->subDays(3)->toDateString());

        $expected = Invoice::all()->filter->isOverdue()->count();
        $this->assertSame(1, $expected);
        $this->assertSame($expected, Invoice::overdue()->count());
        $this->assertSame($expected, (new AdminDashboardService)->get360Data(true)['finance']['overdueCount'] ?? null);
    }

    public function test_the_lawyer_open_ticket_counter_skips_every_final_status(): void
    {
        $this->ticket(TicketStatus::Analyzing->value);
        $this->ticket(TicketStatus::ConvertedToCase->value);
        $this->ticket(TicketStatus::ConvertedToExecution->value);
        $this->ticket(TicketStatus::Completed->value);

        $this->actingAs($this->lawyer)->get('/lawyer/tickets')->assertInertia(fn ($p) => $p
            ->where('counts.needStudy', Ticket::where('assigned_lawyer_id', $this->lawyer->id)->open()->count())
            ->where('counts.needStudy', 1)
            ->where('counts.completed', 1));
    }

    public function test_judged_and_fee_pending_cases_count_as_active_everywhere(): void
    {
        $this->legalCase('منظورة');
        $this->legalCase('صدر الحكم');
        $this->legalCase('بانتظار سداد الأتعاب');
        $this->legalCase('مؤرشفة');

        $this->assertSame(3, LegalCase::active()->count());
        $this->actingAs($this->client)->get('/dashboard')
            ->assertInertia(fn ($p) => $p->where('counts.activeCases', 3));
        $this->assertSame(3, (new AdminDashboardService)->get360Data(true)['overview']['activeCases'] ?? null);
    }
}
