<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ConsultBooking;
use App\Support\TicketJourney;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;

/**
 * **رحلة الاستشارة كما قرّرها المالك (2026-09-14)** — مُركّبات اختبارٍ مشتركة.
 *
 *   الرأي القانوني المعتمد ← طلب ← تسعير ← سداد ← «بانتظار تحديد الموعد»
 *   ← الإدارة تحجز وتنشر | الموظّف يقترح ثمّ الإدارة تعتمد (أو تعدّل وتعتمد).
 *
 * العميل لا يختار موعداً، ولا تُطلب الاستشارة قبل اعتماد الإدارة لملخّص الملفّ.
 */
trait BuildsConsultJourney
{
    use ApprovesTicketSummary;

    /** يعتمد ملخّص الملفّ بمرحلتيه (المحامي ثمّ الإدارة) — شرط طلب الاستشارة. */
    protected function approveOpinionOf(Ticket $ticket): Ticket
    {
        return $this->approveTicketSummary($ticket);
    }

    /**
     * تذكرةٌ اعتمدت الإدارة رأيها المبدئيّ — تقف عند «الرأي القانوني».
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function ticketWithApprovedOpinion(User $client, array $attributes = []): Ticket
    {
        $status = $attributes['status'] ?? 'الرأي القانوني';

        return $this->approveOpinionOf(Ticket::create(array_merge([
            'user_id' => $client->id,
            'number' => 'SB-J-'.uniqid(),
            'type' => 'نزاع تجاري',
            'department' => 'القضايا التجارية',
            'subject' => 'مطالبة',
            'status' => $status,
            'tone' => TicketJourney::toneFor($status),
        ], $attributes)));
    }

    /** الإداريّ الذي يسعّر ويحجز — واحدٌ يُعاد استعماله فلا تتضاعف إشعارات الإدارة. */
    protected function journeyAdmin(): User
    {
        return User::where('role', Role::Admin)->orderBy('id')->first()
            ?? User::factory()->create(['role' => Role::Admin]);
    }

    /** تسعير الإدارة ثمّ سدادٌ مُسوّى ⇒ «بانتظار تحديد الموعد». */
    protected function priceAndPay(Consult $consult, int $price = 450): Consult
    {
        $this->actingAs($this->journeyAdmin())
            ->post(route('admin.consults.price', $consult), ['price' => $price])
            ->assertRedirect();

        $this->assertTrue(ConsultBooking::markPaid($consult->fresh()), 'السداد لم يُسوَّ');

        return $consult->fresh();
    }

    /** العميل يطلب من محادثة التذكرة، والإدارة تسعّر، ويُسدَّد. */
    protected function requestPricedAndPaid(User $client, Ticket $ticket, string $type = 'video', int $price = 450): Consult
    {
        $this->actingAs($client)->post(route('tickets.book', $ticket), ['type' => $type])->assertNoContent();

        return $this->priceAndPay($ticket->consults()->latest('id')->firstOrFail(), $price);
    }

    /**
     * الإدارة تحجز الموعد وتنشره مباشرةً (`POST /admin/schedule`).
     *
     * @param  array<string, mixed>  $input  date · time · lawyer_id · type
     */
    protected function adminPublishes(Consult $consult, array $input): TestResponse
    {
        return $this->actingAs($this->journeyAdmin())->postJson(route('admin.schedule.store'), array_merge([
            'client_id' => $consult->user_id,
            'consult_id' => $consult->id,
        ], $input));
    }

    /** موظّفٌ بصلاحيّة «جدولة المواعيد» — تمنحها الإدارة من تبويب الموظّفين. */
    protected function schedulingEmployee(): User
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $permission = Permission::firstOrCreate(['name' => 'جدولة المواعيد', 'guard_name' => 'web']);
        $employee->givePermissionTo($permission);

        return $employee;
    }

    /**
     * الموظّف يقترح الموعد (`POST /employee/schedule`) — لا يصل العميلَ شيء.
     *
     * @param  array<string, mixed>  $input
     */
    protected function employeeProposes(Consult $consult, array $input, ?User $employee = null): TestResponse
    {
        return $this->actingAs($employee ?? $this->schedulingEmployee())->postJson(route('employee.schedule.store'), array_merge([
            'client_id' => $consult->user_id,
            'consult_id' => $consult->id,
        ], $input));
    }

    /**
     * الإدارة تعتمد اقتراح الموظّف — كما هو أو بعد تعديله.
     *
     * @param  array<string, mixed>  $edits
     */
    protected function adminApprovesAppointment(Consult $consult, array $edits = []): TestResponse
    {
        return $this->actingAs($this->journeyAdmin())
            ->post(route('admin.consults.appointment.approve', $consult), $edits);
    }
}
