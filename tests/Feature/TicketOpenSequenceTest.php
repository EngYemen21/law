<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تسلسل فتح التذكرة ونافذة ردّ AI (إصلاح ارتداد المسار):
 * مع وكيل الاستقبال المفعّل كانت قفزة «محالة للقسم القانوني» الفورية تسبق الترحيب فيرتدّ
 * المسار للخلف ويقرأ العميل «تمت الإحالة» قبل طلب مستنداته — الآن الإحالة حقيقية لا تسبق أوانها،
 * وبعدها يتنحّى AI مع تسليم عصا المتابعة للمستشار بإشعار (لا رسائل تضيع بصمت).
 */
class TicketOpenSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function openTicket(User $client): Ticket
    {
        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'details' => 'أطالب بمستحقاتي بموجب العقد.',
        ])->assertRedirect();

        return Ticket::latest('id')->firstOrFail();
    }

    public function test_agent_open_keeps_journey_forward_and_ai_replies(): void
    {
        config(['services.ai_agent.enabled' => true]);
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);

        $ticket = $this->openTicket($client);

        // لا قفزة «محالة» قبل الترحيب — الاستقبال يطلب المستندات أولاً والإسناد صامت للعزل فقط
        $this->assertSame('بانتظار مستندات', $ticket->status);
        $this->assertNotNull($ticket->assigned_lawyer_id);
        $this->assertStringNotContainsString('تمت إحالة', (string) $ticket->last_message);

        // رسالة العميل في نافذة استكمال المستندات تحصل على ردّ آلي
        $aiBefore = $ticket->messages()->where('who', 'ai')->count();
        $this->actingAs($client)->post(route('tickets.messages.store', $ticket), ['body' => 'متى تبدأ الدراسة؟'])->assertSuccessful();
        $this->assertGreaterThan($aiBefore, $ticket->messages()->where('who', 'ai')->count());
    }

    public function test_human_mode_open_still_refers_immediately(): void
    {
        // الوكيل معطّل (الوضع البشري) — القفزة الفورية تبقى كي لا تعلق التذكرة بلا مستقبِل
        config(['services.ai_agent.enabled' => false]);
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);

        $ticket = $this->openTicket($client);

        $this->assertSame('محالة للقسم القانوني', $ticket->status);
        $this->assertNotNull($ticket->assigned_lawyer_id);
    }

    public function test_client_message_after_referral_hands_off_to_lawyer(): void
    {
        config(['services.ai_agent.enabled' => true]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-8001', 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber',
            'assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'last_message' => '—', 'date_label' => 'الآن',
        ]);

        $aiBefore = $ticket->messages()->where('who', 'ai')->count();
        $this->actingAs($client)->post(route('tickets.messages.store', $ticket), ['body' => 'هل من مستجدات؟'])->assertSuccessful();

        // لا ردّ آلي بعد الإحالة (مقصود) — لكن المستشار المسند يُشعَر فلا تضيع الرسالة بصمت
        $this->assertSame($aiBefore, $ticket->messages()->where('who', 'ai')->count());
        $this->assertTrue(
            UserNotification::where('user_id', $lawyer->id)->where('body', 'like', '%SB-2026-8001%')->exists()
        );
    }
}
