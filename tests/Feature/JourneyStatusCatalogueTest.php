<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\Enums\TicketStatus;
use App\Models\Consult;
use App\Support\TicketJourney;
use Tests\TestCase;

/**
 * **الكتالوج الجديد يحتوي القديم** — نقلُ الحالات إلى التعدادات لا يُسقط قيمةً مخزّنة.
 *
 * القيم هي النصوص العربيّة نفسها، فأيّ حالةٍ تعرفها الثوابت القديمة ولا يعرفها التعداد
 * صفٌّ في القاعدة لا يُقرأ بعد النقل.
 */
class JourneyStatusCatalogueTest extends TestCase
{
    public function test_every_ticket_status_of_the_old_journey_is_in_the_catalogue(): void
    {
        foreach (TicketJourney::statuses() as $status) {
            $this->assertNotNull(TicketStatus::tryFrom($status), "حالة التذكرة «{$status}» غائبة عن التعداد");
        }
    }

    public function test_the_ticket_finals_are_exactly_completed_and_closed(): void
    {
        $this->assertSame([TicketStatus::ConvertedToCase->value, TicketStatus::Closed->value, TicketStatus::Completed->value], TicketStatus::finals());
        $this->assertTrue(TicketStatus::Closed->isFinal());
        $this->assertTrue(TicketStatus::ConvertedToCase->isFinal());
        $this->assertFalse(TicketStatus::Scheduled->isFinal());
    }

    /** الحالات القديمة مقروءة ومعلَّمة — لا تُعرض خياراً ولا يكتبها انتقال. */
    public function test_retired_ticket_statuses_are_marked_legacy(): void
    {
        foreach (['بانتظار الدفع', 'قيد التنفيذ', 'بانتظار اعتماد النتيجة', 'بانتظار اعتماد الإدارة'] as $status) {
            $this->assertTrue(TicketStatus::from($status)->isLegacy(), "«{$status}» يُفترض أن تكون قديمة");
        }
        $this->assertFalse(TicketStatus::AwaitingSessionSummary->isLegacy());
    }

    /** العميل لا يرى أسماء الاعتماد الداخليّة. */
    public function test_internal_approval_states_have_a_client_label(): void
    {
        $this->assertSame('قيد إعداد الرأي القانوني', TicketStatus::AwaitingAdminSummaryApproval->clientLabel());
        $this->assertSame('قيد إعداد الرأي القانوني', TicketStatus::AwaitingLawyerApproval->clientLabel());
        $this->assertSame('بانتظار تحديد الموعد', ConsultStatus::AwaitingAppointmentApproval->clientLabel());
    }

    public function test_every_consult_status_is_in_the_catalogue_and_both_catalogues_match(): void
    {
        foreach (Consult::STATUSES as $status) {
            $this->assertNotNull(ConsultStatus::tryFrom($status), "حالة الاستشارة «{$status}» غائبة عن التعداد");
        }

        // بوّابة اعتماد الموعد دخلت كتالوج النموذج (الدفعة ٣) — فلا حالةَ في التعداد بلا نظيرٍ فيه
        $this->assertSame([], array_values(array_diff(ConsultStatus::values(), Consult::STATUSES)));
        $this->assertContains('بانتظار اعتماد الموعد', Consult::STATUSES);
    }

    public function test_pre_session_includes_the_old_set_plus_the_approval_gate(): void
    {
        foreach (Consult::PRE_SESSION_STATUSES as $status) {
            $this->assertContains($status, ConsultStatus::preSession());
        }
        $this->assertContains('بانتظار اعتماد الموعد', ConsultStatus::preSession());
        $this->assertTrue(ConsultStatus::Cancelled->isClosed());
        $this->assertFalse(ConsultStatus::NoShow->isClosed(), '«لم يحضر» حالة تعافٍ لا نهاية');
    }

    /** «لم تُعقد» نهايةٌ وليست انعقاداً (ع٢١). */
    public function test_session_states_match_and_only_ended_counts_as_held(): void
    {
        $this->assertSame(Consult::SESSIONS, SessionState::values());
        $this->assertTrue(SessionState::Ended->wasHeld());
        $this->assertFalse(SessionState::NotHeld->wasHeld());
    }

    public function test_a_cancelled_invoice_is_not_payable(): void
    {
        $this->assertFalse(InvoiceStatus::Cancelled->isPayable());
        $this->assertFalse(InvoiceStatus::Paid->isPayable());
        $this->assertTrue(InvoiceStatus::Due->isPayable());
    }
}
