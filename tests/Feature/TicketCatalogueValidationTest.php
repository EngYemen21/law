<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalDepartment;
use App\Models\LegalService;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LegalCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * نموذج فتح التذكرة على كتالوج الأقسام: الصفحة تعرض الفعّال وحده، والخادم يرفض قسماً أو خدمةً
 * خارجه أو خدمةً من قسمٍ آخر، ويحفظ الاسم المعتمد مع معرّفه.
 */
class TicketCatalogueValidationTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function department(string $code): LegalDepartment
    {
        return LegalDepartment::where('code', $code)->firstOrFail();
    }

    /** يُرسل نموذج التذكرة باسم العميل مع حقولٍ ثابتة لا يقيسها الاختبار. */
    private function postTicket(User $client, array $payload): TestResponse
    {
        return $this->actingAs($client)->post(route('tickets.store'), $payload + [
            'subject' => 'مطالبة', 'details' => 'تفاصيل الطلب.',
        ]);
    }

    public function test_the_form_lists_only_active_departments_and_services(): void
    {
        $labor = $this->department('labor');
        $insurance = $this->department('insurance');
        $hiddenService = $insurance->services()->firstOrFail();
        $labor->update(['status' => 'suspended']);
        $hiddenService->update(['status' => 'suspended']);

        $this->actingAs($this->client())->get(route('tickets.new'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('newticket')
                ->where('catalogue', fn ($catalogue) => ! collect($catalogue)->contains('id', $labor->id)
                    && ! collect(collect($catalogue)->firstWhere('id', $insurance->id)['services'])->contains('id', $hiddenService->id)));
    }

    public function test_a_ticket_is_saved_with_the_catalogue_names_and_ids(): void
    {
        $client = $this->client();
        $labor = $this->department('labor');
        $service = $labor->services()->firstOrFail();

        $this->postTicket($client, ['department_id' => $labor->id, 'service_id' => $service->id])->assertRedirect();

        $ticket = Ticket::where('user_id', $client->id)->firstOrFail();
        $this->assertSame($labor->name, $ticket->department);
        $this->assertSame($service->name, $ticket->type);
        $this->assertSame($labor->id, $ticket->legal_department_id);
        $this->assertSame($service->id, $ticket->legal_service_id);
    }

    public function test_a_service_from_another_department_is_rejected(): void
    {
        $client = $this->client();
        $foreign = $this->department('real_estate')->services()->firstOrFail();

        $this->postTicket($client, ['department_id' => $this->department('labor')->id, 'service_id' => $foreign->id])
            ->assertSessionHasErrors('service_id');
        $this->assertSame(0, Ticket::where('user_id', $client->id)->count());
    }

    public function test_suspended_department_or_service_is_rejected(): void
    {
        $client = $this->client();
        $labor = $this->department('labor');
        $service = $labor->services()->firstOrFail();

        LegalService::whereKey($service->id)->update(['status' => 'suspended']);
        LegalCatalogue::flush();
        $this->postTicket($client, ['department_id' => $labor->id, 'service_id' => $service->id])->assertSessionHasErrors('service_id');

        $labor->update(['status' => 'suspended']);
        $this->postTicket($client, ['department_id' => $labor->id, 'service_id' => $service->id])->assertSessionHasErrors('department_id');

        $this->assertSame(0, Ticket::where('user_id', $client->id)->count());
    }

    public function test_a_service_is_required_once_a_department_is_chosen(): void
    {
        $this->postTicket($this->client(), ['department_id' => $this->department('labor')->id])
            ->assertSessionHasErrors(['service_id' => 'اختر الخدمة المتعلقة بالتذكرة.']);
    }

    public function test_a_legacy_department_name_is_accepted_only_when_it_matches(): void
    {
        $client = $this->client();

        $this->postTicket($client, ['type' => 'نزاع تجاري', 'department' => 'القسم التجاري'])->assertRedirect();
        $ticket = Ticket::where('user_id', $client->id)->firstOrFail();
        $this->assertSame('القضايا التجارية', $ticket->department);
        $this->assertSame($this->department('commercial')->id, $ticket->legal_department_id);

        $this->postTicket($client, ['type' => 'نزاع', 'department' => 'قسمٌ لا وجود له'])->assertSessionHasErrors('department');
        $this->assertSame(1, Ticket::where('user_id', $client->id)->count());
    }
}
