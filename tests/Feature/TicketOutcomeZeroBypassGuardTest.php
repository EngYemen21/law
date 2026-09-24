<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketOutcomeTrack;
use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * حراسة معمارية صارمة لمنع الالتفاف على الحوكمة (Zero-Bypass Architecture Guards - ADR-009).
 *
 * القواعد المعمارية الثابتة:
 * 1. حظر التحويل المباشر إلى قضية من أي مسار قديم (محامٍ، موظف، إدارة).
 * 2. حظر الإغلاق المباشر دون قضية من أي مسار قديم.
 * 3. حظر التحويل المباشر إلى استشارة من أي مسار قديم.
 * 4. عدم وجود أي مسار مباشر للتحويل إلى تنفيذ إطلاقاً.
 * 5. القرارات المصيرية الأربعة (استشارة، قضية، تنفيذ، إغلاق) تنفذ حصراً عبر مسار اعتماد الحوكمة المسبّب (ApproveOutcomeTrack).
 * 6. خلو شاشات الواجهة من أي أزرار أو استدعاءات لمسارات الالتفاف القديمة.
 */
class TicketOutcomeZeroBypassGuardTest extends TestCase
{
    use RefreshDatabase;

    private function completedTicket(User $client, ?User $lawyer = null): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-9555',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => $lawyer?->name ?? 'أ. سارة القحطاني',
            'assigned_lawyer_id' => $lawyer?->id,
            'status' => TicketStatus::Completed->value,
            'tone' => 'b-green',
        ]);
    }

    /** حارس 1: مسارات التحويل المباشر للقضية محذوفة نهائياً من سجل المسارات (ADR-009) */
    public function test_direct_case_conversion_routes_are_fully_removed(): void
    {
        $this->assertFalse(Route::has('lawyer.tickets.convert'), 'مسار lawyer.tickets.convert لا يزال مسجلاً.');
        $this->assertFalse(Route::has('employee.tickets.convert'), 'مسار employee.tickets.convert لا يزال مسجلاً.');
        $this->assertFalse(Route::has('admin.tickets.convert'), 'مسار admin.tickets.convert لا يزال مسجلاً.');
        $this->assertFalse(Route::has('tickets.convert'), 'مسار tickets.convert لا يزال مسجلاً.');
    }

    /** حارس 2: مسارات الإغلاق المباشر محذوفة نهائياً من سجل المسارات (ADR-009) */
    public function test_direct_close_routes_are_fully_removed(): void
    {
        $this->assertFalse(Route::has('lawyer.tickets.close'), 'مسار lawyer.tickets.close لا يزال مسجلاً.');
        $this->assertFalse(Route::has('employee.tickets.close'), 'مسار employee.tickets.close لا يزال مسجلاً.');
        $this->assertFalse(Route::has('admin.tickets.close'), 'مسار admin.tickets.close لا يزال مسجلاً.');
        $this->assertFalse(Route::has('tickets.close'), 'مسار tickets.close لا يزال مسجلاً.');
    }

    /** حارس 3: مسارات تحويل الاستشارة المباشرة محذوفة نهائياً من سجل المسارات (ADR-009) */
    public function test_direct_convert_to_consult_routes_are_fully_removed(): void
    {
        $this->assertFalse(Route::has('employee.tickets.convert-consult'), 'مسار employee.tickets.convert-consult لا يزال مسجلاً.');
        $this->assertFalse(Route::has('admin.tickets.convert-consult'), 'مسار admin.tickets.convert-consult لا يزال مسجلاً.');
        $this->assertFalse(Route::has('tickets.convert-consult'), 'مسار tickets.convert-consult لا يزال مسجلاً.');
    }

    /** حارس 8: تأكيد عدم وجود أي مسار HTTP لتحويل التنفيذ خارج الحوكمة */
    public function test_there_is_no_direct_route_for_execution_conversion(): void
    {
        $this->assertFalse(Route::has('tickets.convert-execution'));
        $this->assertFalse(Route::has('lawyer.tickets.convert-execution'));
        $this->assertFalse(Route::has('employee.tickets.convert-execution'));
        $this->assertFalse(Route::has('admin.tickets.convert-execution'));
    }

    /** حارس 9: مسار اعتماد الحوكمة هو المسار الحصري لتنفيذ القرارات المصيرية وتجميد التذكرة */
    public function test_governance_route_is_exclusive_path_for_outcome_and_freezing(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $ticket = $this->completedTicket($client, $lawyer);

        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Case->value,
            'reason' => 'اعتماد الإدارة العليا لمسار القضية بموجب معايير الحوكمة.',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::ConvertedToCase->value, $ticket->status);
        $this->assertTrue($ticket->is_frozen);
        $this->assertSame(1, LegalCase::where('ticket_id', $ticket->id)->count());

        // تذكرة مجمدة لا تقبل أي تغيير مسار لاحق
        $this->actingAs($admin)->post(route('admin.tickets.track.approve', $ticket), [
            'track' => TicketOutcomeTrack::Close->value,
            'reason' => 'محاولة إغلاق تذكرة مجمدة.',
        ])->assertStatus(422);
    }

    /** حارس 10: حراسة الواجهة - خلو الألواح من أي أزرار أو استدعاءات لمسارات الالتفاف */
    public function test_frontend_action_panels_have_zero_bypass_endpoints(): void
    {
        $panel = (string) file_get_contents(resource_path('js/components/babylon/TicketActionsPanel.tsx'));
        $lawyerChat = (string) file_get_contents(resource_path('js/pages/lawyer/ticketchat.tsx'));
        $employeeChat = (string) file_get_contents(resource_path('js/pages/employee/ticketchat.tsx'));

        foreach ([$panel, $lawyerChat, $employeeChat] as $src) {
            // إزالة التعليقات لضمان فحص الكود الفعلي
            $code = (string) preg_replace('#\{/\*.*?\*/\}#s', '', $src);
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
            $code = (string) preg_replace('#//[^\n]*#', '', $code);

            $this->assertStringNotContainsString('/convert`', $code);
            $this->assertStringNotContainsString('tickets.convert', $code);
            $this->assertStringNotContainsString('/convert-consult`', $code);
            $this->assertStringNotContainsString('tickets.convert-consult', $code);
            $this->assertStringNotContainsString('/close`', $code);
        }
    }
}
