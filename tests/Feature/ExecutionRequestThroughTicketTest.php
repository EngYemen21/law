<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ExecFlow;
use App\Support\ExecService;
use App\Support\ExecutionCreation;
use App\Support\LegalCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * **طلب التنفيذ يُفتح تذكرةً في قسم التنفيذ** (قرار المالك 2026-09-29).
 *
 * كان زرّ العميل «طلب تنفيذ جديد» يُنشئ ملفّ تنفيذٍ مباشرةً بلا تذكرة — بابٌ ثانٍ موازٍ لقسم
 * التنفيذ في التذاكر يتجاوز بطاقة القرار واعتماد المسار. صار الزرّ يفتح نموذج التذكرة على
 * قسم التنفيذ، ويُسأل فيه عن نوع السند وقيمة المطالبة والمنفَّذ ضدّه، فتنتقل إلى ملفّ التنفيذ
 * عند اعتماد المسار (`ExecutionCreation::fromTicket`).
 */
class ExecutionRequestThroughTicketTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    /** @return array{department_id: int, service_id: int} */
    private function enforcement(): array
    {
        $dept = LegalCatalogue::department(LegalCatalogue::ENFORCEMENT_CODE);
        $this->assertNotNull($dept, 'قسم التنفيذ في الكتالوج');

        return ['department_id' => $dept->id, 'service_id' => $dept->services->first()->id];
    }

    private function openTicket(User $client, array $fields): TestResponse
    {
        Queue::fake(); // الفرز الذكيّ عند الفتح خارج ما يُختبر هنا

        return $this->actingAs($client)->post(route('tickets.store'), array_merge([
            'subject' => 'تحصيل قيمة شيك مرتجع',
            'details' => 'شيك مرتجع بلا رصيد.',
        ], $fields));
    }

    public function test_the_button_opens_the_ticket_form_on_the_enforcement_department(): void
    {
        $this->actingAs($this->client())->get(route('tickets.new', ['department' => 'enforcement']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('newticket')
                ->where('preselectDepartmentId', $this->enforcement()['department_id'])
                ->where('enforcementId', $this->enforcement()['department_id'])
                ->where('execSanads', ExecFlow::SANADS));

        // والزرّ في صفحة التنفيذ رابطٌ إليه — لا نموذج مباشر
        $page = (string) file_get_contents(resource_path('js/pages/execflow.tsx'));
        $this->assertStringContainsString('href="/tickets/new?department=enforcement"', $page);
        $this->assertStringNotContainsString("router.post('/exec-flow'", $page);
    }

    public function test_an_enforcement_ticket_requires_a_known_sanad(): void
    {
        $client = $this->client();

        $this->openTicket($client, $this->enforcement())->assertSessionHasErrors('exec_sanad');
        $this->openTicket($client, $this->enforcement() + ['exec_sanad' => 'ورقة عاديّة'])->assertSessionHasErrors('exec_sanad');

        $this->assertSame(0, Ticket::count(), 'لا تذكرة تنفيذ بلا سند معروف');
    }

    public function test_an_enforcement_ticket_keeps_the_sanad_amount_and_opponent(): void
    {
        $this->openTicket($this->client(), $this->enforcement() + [
            'exec_sanad' => 'شيك', 'claim_amount' => 85000, 'opponent_name' => 'مؤسسة الرمال',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $ticket = Ticket::sole();
        $this->assertSame('شيك', $ticket->exec_sanad);
        $this->assertSame(85000, (int) $ticket->claim_amount);
        $this->assertSame('مؤسسة الرمال', $ticket->opponent_name);
    }

    public function test_another_department_never_stores_a_sanad(): void
    {
        $labor = LegalCatalogue::department('labor');

        $this->openTicket($this->client(), [
            'department_id' => $labor->id, 'service_id' => $labor->services->first()->id, 'exec_sanad' => 'شيك',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(Ticket::sole()->exec_sanad);
    }

    public function test_the_ticket_data_reaches_the_execution_file_when_the_track_is_approved(): void
    {
        $client = $this->client();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-EXEC-1', 'type' => 'تنفيذ الشيكات', 'status' => 'بانتظار قرار المآل',
            'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            'exec_sanad' => 'شيك', 'claim_amount' => 85000, 'opponent_name' => 'مؤسسة الرمال',
        ]);

        $exec = ExecutionCreation::fromTicket($ticket, $admin);

        $this->assertSame('شيك', $exec->sanad);
        $this->assertSame(85000, (int) $exec->amount);
        $this->assertSame('مؤسسة الرمال', $exec->defendant);
        $this->assertSame($ticket->id, $exec->ticket_id);
    }

    public function test_the_direct_request_path_is_gone(): void
    {
        $this->actingAs($this->client())->post('/exec-flow', [
            'sanad' => 'شيك', 'subject' => 'تحصيل', 'amount' => 1000,
        ])->assertNotFound();

        $this->assertSame(0, Execution::count());
        $this->assertFalse(method_exists(ExecService::class, 'submit'), 'لا منشئ ملفّ تنفيذ بلا تذكرة ولا قضيّة');
    }
}
