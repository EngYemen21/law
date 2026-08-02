<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حجز الاستشارة الحقيقي (العطل المُبلَّغ) — عميل مباشر + موظف نيابةً + الأسعار + المحاسبة.
 */
class DirectBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_direct_booking_creates_pricing_request(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        // الحجز المباشر صار طلباً بانتظار التسعير (لا موعد ولا دفع بعد) — يُكمل في «استشاراتي»
        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'phone', 'specialty' => 'القضايا العمالية', 'subject' => 'نزاع عمل',
        ])->assertRedirect(route('myconsults'));

        $consult = Consult::firstOrFail();
        $this->assertSame($client->id, $consult->user_id);
        $this->assertNull($consult->ticket_id); // حجز مباشر بلا تذكرة
        $this->assertSame('هاتفية', $consult->channel);
        $this->assertSame('بانتظار التسعير', $consult->status);
        $this->assertSame(350, $consult->price); // السعر الابتدائي المقترح (الافتراضي للهاتفية)
        $this->assertNull($consult->appointment_id);
        $this->assertSame(0, Appointment::count()); // لا موعد قبل السداد
    }

    public function test_employee_books_on_behalf_of_client(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض', 'name' => 'أ. سارة القحطاني']);

        $date = LawyerAvailability::resolveDate(null)->toDateString();
        $this->actingAs($employee)->post(route('employee.schedule.store'), [
            'client_id' => $client->id, 'type' => 'office', 'date' => $date, 'time' => '13:00',
            'lawyer_id' => $lawyer->id, 'subject' => 'عقد',
        ])->assertRedirect();

        $consult = Consult::firstOrFail();
        $this->assertSame($client->id, $consult->user_id);
        $this->assertSame('حضورية', $consult->channel);
        $this->assertSame('أ. سارة القحطاني', $consult->lawyer);
        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id); // مربوط بالمعرّف والفرع
        $this->assertSame('فرع الرياض', $consult->branch);
        $this->assertNotNull($consult->starts_at); // وقت حقيقي (لا نصّ) — يفعّل منع التعارض وجدولة Zoom
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
            'type' => 'phone', 'specialty' => 'القضايا التجارية',
        ])->assertRedirect();
        $this->assertSame(400, Consult::firstOrFail()->price); // السعر الجديد انعكس على الطلب
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
