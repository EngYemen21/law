<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiReviewOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مراحل ما بعد الحجز (قرار المالك 2026-09-14): ملخّص الجلسة نصٌّ واحد يعتمده المستشار ثمّ
 * الإدارة، واعتماد الإدارة له هو نفسه اكتمال التذكرة. والمسار القديم (محضرٌ يركّبه الموظّف،
 * واعتماد «النتيجة» على مرحلتين) مقروءٌ للصفوف القائمة وحدها.
 */
class SessionResultFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Ticket, 1: Consult} */
    private function confirmedTicket(User $client, ?User $lawyer = null): array
    {
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-8888',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
            'status' => 'موعد مؤكد',
            'tone' => 'b-green',
        ]);
        $ticket->summary()->create([
            'case_summary' => 'ملخص القضية',
            'attachments_summary' => 'مستند واحد',
            'facts' => "• واقعة أولى.\n• واقعة ثانية.",
            'key_points' => "• توصية أولى.\n• توصية ثانية.",
            'status' => 'approved',
            'result_status' => 'none',
        ]);

        // **الجلسة انعقدت فعلاً**
        $consult = Consult::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'ref' => 'CN-SRF-'.uniqid(),
            'subject' => 'نزاع تجاري', 'type' => 'استشارة', 'channel' => 'مرئية',
            'status' => 'منتهية', 'session' => 'منتهية', 'lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
        ]);

        return [$ticket, $consult];
    }

    /** **الموظّف لا يوثّق جلسةً ولا يركّب نتيجتها** — كان «عقد الجلسة» يكتب محضراً ويُعلن الانعقاد (ع١٢، ع٢١). */
    public function test_employee_cannot_declare_the_session_or_compose_its_result(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        [$ticket] = $this->confirmedTicket($client);

        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertStatus(422);

        $ticket->refresh();
        $this->assertSame('موعد مؤكد', $ticket->status);
        $this->assertSame('none', $ticket->summary->result_status);
        $this->assertEmpty($ticket->summary->result);
        $this->assertFalse($ticket->messages->contains(fn ($m) => $m->role === 'محضر الجلسة'));
    }

    /**
     * **ملخّص الجلسة واحد:** اعتماد الإدارة له يُكمل التذكرة، وبطاقة المحادثة و«استشاراتي»
     * بالنصّ نفسه — كان للجلسة ملخّصان مختلفان بعنوانٍ واحد.
     */
    public function test_admin_approving_the_session_summary_completes_the_ticket_with_the_same_text(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        [$ticket, $consult] = $this->confirmedTicket($client, $lawyer);
        $ticket->update(['status' => 'بانتظار ملخّص الجلسة']);

        $text = 'ملخّص الجلسة: تُطالَب الشركة بالمستحقات، ويُرسل إنذارٌ خطّيّ خلال أسبوع.';
        $consult->update(['summary' => $text]);

        $this->assertTrue(AiReviewOutcome::lawyerApproveConsultSummary($consult->fresh(), $lawyer));
        $this->assertSame('بانتظار ملخّص الجلسة', $ticket->fresh()->status, 'اعتماد المستشار لا يُكمل');
        $this->assertSame(0, UserNotification::where('user_id', $client->id)->count());

        $this->actingAs($admin)->post("/admin/consults/{$consult->id}/summary/approve")->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::ReadyForOutcome->value, $ticket->status);
        $this->assertSame('approved', $ticket->summary->result_status);

        $card = $ticket->messages->first(fn ($m) => $m->role === 'النتيجة');
        $this->assertNotNull($card);
        $this->assertStringContainsString($text, $card->body);
        $this->assertSame($text, $consult->fresh()->toClientCard()['summary']);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'admin'));

        // العميل يرى بطاقة النتيجة
        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('messages', fn ($m) => collect($m)->contains(fn ($x) => $x['role'] === 'النتيجة')));
    }

    // ── المسار القديم: للصفوف القائمة قبل إعادة البناء ──

    public function test_lawyer_raises_a_legacy_result_to_admin(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        [$ticket] = $this->confirmedTicket($client, $lawyer);
        $ticket->summary->update(['result_status' => 'pending_lawyer', 'result' => 'نتيجة الجلسة']);

        $this->actingAs($lawyer)->post(route('lawyer.result.approve', $ticket))->assertRedirect();

        $ticket->refresh();
        $this->assertSame('بانتظار اعتماد الإدارة', $ticket->status);
        $this->assertSame('pending_admin', $ticket->summary->result_status);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'lawyer' && $m->role === 'اعتماد'));
    }

    public function test_admin_final_approval_delivers_a_legacy_result_to_client(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        [$ticket] = $this->confirmedTicket($client);
        $ticket->summary->update(['result_status' => 'pending_admin', 'result' => 'نتيجة الجلسة']);

        $this->actingAs($admin)->post(route('admin.tickets.result', $ticket))->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::ReadyForOutcome->value, $ticket->status);
        $this->assertSame('approved', $ticket->summary->result_status);

        // بطاقة النتيجة + اعتماد الإدارة في المحادثة، وإشعار للعميل
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'النتيجة'));
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->who === 'admin'));
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());

        // العميل يرى بطاقة النتيجة
        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('messages', fn ($m) => collect($m)->contains(fn ($x) => $x['role'] === 'النتيجة')));
    }

    public function test_admin_cannot_approve_before_lawyer(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        [$ticket] = $this->confirmedTicket(User::factory()->create(['role' => Role::Client]));
        // result_status still 'none' → admin approval rejected
        $this->actingAs($admin)->post(route('admin.tickets.result', $ticket))->assertNotFound();
    }
}
