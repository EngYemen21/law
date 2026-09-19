<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Support\TicketJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **«بانتظار الاعتماد» عند الإدارة يعدّ ما ينتظرها فعلاً** (تدقيق اللوحات ١–٢).
 *
 * كان تبويب التذاكر يقرأ الحالة القديمة «بانتظار اعتماد الإدارة» و`result_status='pending_admin'`
 * وحدهما، ولا كاتب حيّاً لهما في الرحلة الجديدة — فيعرض صفراً وصفحة «بانتظار اعتمادك» مليئة.
 * ومؤشّر اللوحة كان يعدّ كلّ ملخّصٍ لم تعتمده الإدارة، بما فيه ما لم يعتمده المحامي بعد.
 */
class AdminApprovalCountersTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(User $client, string $status): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-AP-'.uniqid(), 'type' => 'نزاع',
            'status' => $status, 'tone' => TicketJourney::toneFor($status),
        ]);
    }

    private function summary(Ticket $ticket, string $status, array $extra = []): TicketSummary
    {
        return TicketSummary::create(array_merge([
            'ticket_id' => $ticket->id, 'facts' => 'وقائع', 'key_points' => 'توصيات', 'status' => $status,
        ], $extra));
    }

    public function test_pending_admin_tab_and_count_include_the_two_stage_states(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        // (أ) ملخّص ملفّ اعتمده المحامي
        $fileSummary = $this->ticket($client, 'بانتظار اعتماد الإدارة للملخّص');
        $this->summary($fileSummary, 'awaiting_admin', ['lawyer_approved_at' => now()]);

        // (ب) ملخّص جلسة اعتمده المحامي
        $sessionSummary = $this->ticket($client, 'بانتظار ملخّص الجلسة');
        Consult::create([
            'user_id' => $client->id, 'ticket_id' => $sessionSummary->id, 'ref' => 'CN-AP-'.uniqid(),
            'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'أ. سارة', 'status' => 'قيد الاستشارة', 'session' => 'منتهية',
            'summary' => 'ملخّص', 'summary_lawyer_approved_at' => now(),
        ]);

        // (ج) الحالة القديمة «بانتظار اعتماد الإدارة» حُذفت (2026-09-19) — لا صفّ لها يُعدّ

        // (د) ما زال عند المحامي — لا ينتظر الإدارة
        $atLawyer = $this->ticket($client, 'بانتظار اعتماد المستشار');
        $this->summary($atLawyer, 'awaiting_lawyer');

        // (هـ) ملخّص جلسة لم يعتمده المحامي بعد
        $sessionAtLawyer = $this->ticket($client, 'بانتظار ملخّص الجلسة');
        Consult::create([
            'user_id' => $client->id, 'ticket_id' => $sessionAtLawyer->id, 'ref' => 'CN-AP-'.uniqid(),
            'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'أ. سارة', 'status' => 'قيد الاستشارة', 'session' => 'منتهية',
            'summary' => 'ملخّص',
        ]);

        $this->actingAs($admin)->get(route('admin.tickets', ['status' => 'pending_admin']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('tickets.meta.total', 2)
                ->where('summaryStats.pending_admin', 2));
    }

    public function test_dashboard_summary_radar_counts_only_summaries_awaiting_admin(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->summary($this->ticket($client, 'بانتظار اعتماد الإدارة للملخّص'), 'awaiting_admin', ['lawyer_approved_at' => now()]);
        $this->summary($this->ticket($client, 'بانتظار اعتماد المستشار'), 'awaiting_lawyer');
        $this->summary($this->ticket($client, 'الرأي القانوني'), 'approved', ['lawyer_approved_at' => now(), 'approved_at' => now()]);

        $radar = collect(app(AdminDashboardService::class)->get360Data(true)['radar']);
        $item = $radar->firstWhere('id', 'pending-summaries');

        $this->assertNotNull($item);
        $this->assertSame(1, $item['count']);
    }
}
