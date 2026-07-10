<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حجز الاستشارة الحقيقي (العطل المُبلَّغ) — عميل مباشر + موظف نيابةً + الأسعار + المحاسبة.
 */
class DirectBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_direct_booking_creates_consult_and_appointment(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'phone', 'day' => 'الأحد 12 يوليو', 'time' => '11:00 ص', 'subject' => 'نزاع عمل',
        ])->assertRedirect(route('myconsults'));

        $consult = Consult::firstOrFail();
        $this->assertSame($client->id, $consult->user_id);
        $this->assertNull($consult->ticket_id); // حجز مباشر بلا تذكرة
        $this->assertSame('هاتفية', $consult->channel);
        $this->assertSame(350, $consult->price); // السعر الافتراضي للهاتفية
        $this->assertSame(1, Appointment::where('user_id', $client->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->count());
    }

    public function test_employee_books_on_behalf_of_client(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($employee)->post(route('employee.schedule.store'), [
            'client_id' => $client->id, 'type' => 'office', 'day' => 'الاثنين 13 يوليو', 'time' => '01:00 م',
            'lawyer' => 'أ. سارة القحطاني', 'subject' => 'عقد',
        ])->assertRedirect();

        $consult = Consult::firstOrFail();
        $this->assertSame($client->id, $consult->user_id);
        $this->assertSame('حضورية', $consult->channel);
        $this->assertSame('أ. سارة القحطاني', $consult->lawyer);
    }

    public function test_admin_price_change_applies_to_new_bookings(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($admin)->post(route('admin.prices.update'), [
            'office' => 800, 'video' => 500, 'phone' => 400, 'vat' => 15,
        ])->assertRedirect();
        $this->assertSame('400', Setting::get('price_phone'));

        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'phone', 'day' => 'الثلاثاء', 'time' => '10:00 ص',
        ])->assertRedirect();
        $this->assertSame(400, Consult::firstOrFail()->price); // السعر الجديد انعكس
    }

    public function test_admin_accounting_shows_real_invoices_and_marks_paid(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $inv = Invoice::create(['user_id' => $client->id, 'number' => 'INV-9', 'description' => 'أتعاب',
            'amount' => 11500, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 14 يوماً', 'paid' => false]);

        $this->actingAs($admin)->get(route('admin.accounting'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/accounting')
            ->where('totals.issued', 11500)->where('totals.collected', 0)->has('invoices', 1));

        $this->actingAs($admin)->post(route('admin.invoices.pay', $inv))->assertRedirect();
        $this->assertTrue($inv->fresh()->paid);
    }
}
