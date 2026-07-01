<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحقّق من حجز موعد الاستشارة من داخل محادثة التذكرة (مرحلة «بانتظار حجز الاستشارة»).
 */
class ConsultBookingTest extends TestCase
{
    use RefreshDatabase;

    private function ticketReadyToBook(User $client): Ticket
    {
        return Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-7777',
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer' => 'أ. سارة القحطاني',
            'status' => 'بانتظار حجز الاستشارة',
            'tone' => 'b-amber',
        ]);
    }

    public function test_client_books_consultation_from_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketReadyToBook($client);

        $this->actingAs($client)->post(route('tickets.book', $ticket), [
            'type' => 'video',
            'day' => 'الإثنين 29 يونيو',
            'time' => '11:30 ص',
        ])->assertNoContent();

        // أُنشئ موعد حقيقي مرتبط بالتذكرة
        $appt = Appointment::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($appt);
        $this->assertSame($client->id, $appt->user_id);
        $this->assertSame('استشارة مرئية', $appt->type);
        $this->assertSame('11:30 ص', $appt->time);
        $this->assertSame('أ. سارة القحطاني', $appt->lawyer);

        // تقدّمت التذكرة إلى «موعد مؤكد» + رسالة بطاقة الموعد + إشعار
        $ticket->refresh();
        $this->assertSame('موعد مؤكد', $ticket->status);
        $this->assertTrue($ticket->messages->contains(fn ($m) => $m->role === 'مواعيد'));
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());

        // يظهر الموعد في صفحة مواعيد العميل
        $this->actingAs($client)->get(route('appointments'))
            ->assertOk()->assertInertia(fn ($p) => $p->has('appointments', 1));
    }

    public function test_office_booking_uses_selected_branch(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketReadyToBook($client);

        $this->actingAs($client)->post(route('tickets.book', $ticket), [
            'type' => 'office',
            'day' => 'الثلاثاء 30 يونيو',
            'time' => '01:00 م',
            'branch' => 'جدة — حي الروضة',
        ])->assertNoContent();

        $appt = Appointment::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('استشارة حضورية', $appt->type);
        $this->assertSame('جدة — حي الروضة', $appt->branch);
    }

    public function test_booking_validates_type(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketReadyToBook($client);

        $this->actingAs($client)->post(route('tickets.book', $ticket), [
            'type' => 'invalid', 'day' => 'غدًا', 'time' => '10:00 ص',
        ])->assertSessionHasErrors('type');
    }

    public function test_other_client_cannot_book_on_foreign_ticket(): void
    {
        $owner = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->ticketReadyToBook($owner);
        $intruder = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($intruder)->post(route('tickets.book', $ticket), [
            'type' => 'video', 'day' => 'غدًا', 'time' => '10:00 ص',
        ])->assertForbidden();
    }
}
