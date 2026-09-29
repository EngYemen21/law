<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsConsultJourney;
use Tests\TestCase;

/**
 * حجز الاستشارة الحقيقي (العطل المُبلَّغ) — عميل مباشر + موظف نيابةً + المحاسبة.
 */
class DirectBookingTest extends TestCase
{
    use BuildsConsultJourney;
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
        $this->assertSame(0, $consult->price); // بلا سعرٍ مقترح — يسعّره المسعّر (قرار المالك 2026-09-29)
        $this->assertNull($consult->appointment_id);
        $this->assertSame(0, Appointment::count()); // لا موعد قبل السداد
    }

    public function test_client_direct_booking_normalizes_svc_department_specialty(): void
    {
        // الواجهة ترسل قسم SVC كما هو (مثال: «قسم القضايا العمالية») بدل التخصّص المعتمد مباشرة
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'phone', 'specialty' => 'قسم القضايا العمالية', 'subject' => 'قضية عمالية — نزاع أجور',
        ])->assertRedirect(route('myconsults'));

        $this->assertSame('القضايا العمالية', Consult::firstOrFail()->specialty);
    }

    /**
     * **الموظّف يقترح والإدارة تعتمد** (قرار المالك 2026-09-14) — على الاستشارة المدفوعة نفسها،
     * لا حجزٌ «مدفوع مسبقاً» جديد.
     */
    public function test_employee_books_on_behalf_of_client(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active', 'name' => 'أ. سارة القحطاني']);

        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'office', 'specialty' => 'القضايا التجارية', 'subject' => 'عقد',
        ])->assertRedirect(route('myconsults'));
        $consult = $this->priceAndPay(Consult::firstOrFail(), 600);

        $date = now()->addDays(2)->toDateString();
        $this->actingAs($this->schedulingEmployee())->post(route('employee.schedule.store'), [
            'client_id' => $client->id, 'type' => 'office', 'date' => $date, 'time' => '13:00',
            'lawyer_id' => $lawyer->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        // الاقتراح لا يصل العميل: لا وقتَ على الاستشارة حتى تعتمد الإدارة
        $this->assertSame('بانتظار اعتماد الموعد', $consult->fresh()->status);
        $this->assertNull($consult->fresh()->starts_at);
        $this->assertSame(1, Consult::count(), 'ولا تُنشأ استشارةٌ ثانية');

        $this->adminApprovesAppointment($consult->fresh())->assertRedirect()->assertSessionHasNoErrors();

        $consult->refresh();
        $this->assertSame($client->id, $consult->user_id);
        $this->assertSame('جديدة', $consult->status);
        $this->assertSame('حضورية', $consult->channel);
        $this->assertSame('أ. سارة القحطاني', $consult->lawyer);
        $this->assertSame($lawyer->id, $consult->assigned_lawyer_id); // مربوط بالمعرّف لا بالاسم
        $this->assertNotNull($consult->starts_at); // وقت حقيقي (لا نصّ) — يفعّل منع التعارض وجدولة Zoom
    }

    public function test_book_page_lists_only_current_client_pending_requests(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $other = User::factory()->create(['role' => Role::Client]);

        // للعميل الحالي: طلب بانتظار التسعير — يجب أن يظهر
        $this->actingAs($client)->post(route('book.store'), [
            'type' => 'phone', 'specialty' => 'القضايا التجارية', 'subject' => 'نزاع',
        ])->assertRedirect();

        // لعميل آخر: يجب ألا يظهر في قائمة العميل الحالي
        $this->actingAs($other)->post(route('book.store'), [
            'type' => 'video', 'specialty' => 'القضايا التجارية', 'subject' => 'استشارة أخرى',
        ])->assertRedirect();

        $this->actingAs($client)->get(route('book'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('book')
                ->has('pending', 1)
                ->where('pending.0.status', 'بانتظار التسعير')
                // يُمرَّر التخصّص للعميل حتى يُصفّي منتقي الأوقات بالمختصّين لا كل المحامين
                ->where('pending.0.specialty', 'القضايا التجارية'));
    }

    public function test_admin_finance_shows_real_invoices_and_marks_paid(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $inv = Invoice::create(['user_id' => $client->id, 'number' => 'INV-9', 'description' => 'أتعاب',
            'amount' => 11500, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 14 يوماً', 'paid' => false]);

        $this->actingAs($admin)->get(route('admin.finance'))
            ->assertOk()->assertInertia(fn ($p) => $p->component('admin/finance')
            ->where('dashboard.issued', 11500)->where('dashboard.collected', 0));

        $this->actingAs($admin)->get(route('admin.finance', ['tab' => 'invoices']))
            ->assertOk()->assertInertia(fn ($p) => $p->has('invoices.data', 1));

        $this->actingAs($admin)->post(route('admin.invoices.pay', $inv))->assertRedirect();
        $this->assertTrue($inv->fresh()->paid);
    }
}
