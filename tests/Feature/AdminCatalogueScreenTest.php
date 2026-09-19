<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\LegalCatalogueAlias;
use App\Models\LegalDepartment;
use App\Models\StaffDepartment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerSpecialties;
use App\Support\LegalCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * شاشة «الأقسام والخدمات»: للإدارة وحدها، وإعادة التسمية تظهر في كلّ مكان وتحفظ الاسم القديم،
 * والإيقاف ذو الأثر يشترط تأكيداً، ولا تكرار في الأسماء.
 */
class AdminCatalogueScreenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function department(string $code): LegalDepartment
    {
        return LegalDepartment::where('code', $code)->firstOrFail();
    }

    private function ticketIn(LegalDepartment $department, array $attributes = []): Ticket
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Ticket::create($attributes + [
            'user_id' => $client->id, 'number' => 'SB-CAT-'.uniqid(), 'type' => $department->services()->firstOrFail()->name,
            'subject' => 'اختبار', 'department' => $department->name, 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'priority' => 'متوسطة',
        ]);
    }

    public function test_only_management_reaches_the_screen(): void
    {
        foreach ([Role::Employee, Role::Lawyer, Role::Client] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('admin.catalogue'))->assertRedirect();
        }

        $this->actingAs($this->admin())->get(route('admin.catalogue'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/catalogue')
                ->has('departments', LegalDepartment::count())
                ->has('staffDepartments', StaffDepartment::count()));
    }

    public function test_renaming_a_department_updates_old_records_and_keeps_the_old_name_matching(): void
    {
        $labor = $this->department('labor');
        $ticket = $this->ticketIn($labor);
        $case = LegalCase::create([
            'user_id' => $ticket->user_id, 'ticket_id' => $ticket->id, 'number' => 'CASE-CAT-1', 'type' => 'نزاع',
            'department' => $labor->name, 'status' => 'بانتظار اعتماد الأتعاب', 'tone' => 'b-amber', 'fee_status' => 'none',
        ]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        LawyerSpecialties::sync($lawyer, [$labor->id], false);

        $this->actingAs($this->admin())->put(route('admin.catalogue.departments.update', $labor), ['name' => 'قضايا العمل والعمّال'])
            ->assertSessionHasNoErrors();

        $this->assertSame('قضايا العمل والعمّال', $ticket->fresh()->department);
        $this->assertSame('قضايا العمل والعمّال', $case->fresh()->department);
        $this->assertSame('قضايا العمل والعمّال', $lawyer->fresh()->department);
        // الاسم القديم محفوظٌ مطابقةً للقسم نفسه (صفّاً جديداً، أو صفّاً سبق بالصيغة الموحّدة نفسها)
        $this->assertTrue(LegalCatalogueAlias::where('alias_folded', LegalCatalogue::foldName('القضايا العمالية'))->where('legal_department_id', $labor->id)->exists());
        $this->assertSame($labor->id, LegalCatalogue::resolveDepartment('القضايا العمالية')?->id, 'الاسم القديم ما زال يُطابَق.');
    }

    public function test_renaming_a_service_updates_ticket_types(): void
    {
        $labor = $this->department('labor');
        $service = $labor->services()->firstOrFail();
        $oldName = $service->name;
        $ticket = $this->ticketIn($labor);
        $this->assertSame($service->id, $ticket->legal_service_id);

        $this->actingAs($this->admin())->put(route('admin.catalogue.services.update', $service), ['name' => 'خدمةٌ بصياغةٍ جديدة'])
            ->assertSessionHasNoErrors();

        $this->assertSame('خدمةٌ بصياغةٍ جديدة', $ticket->fresh()->type);
        $this->assertSame($service->id, LegalCatalogue::resolveService($oldName, $labor->id)?->id, 'الاسم القديم ما زال يُطابَق.');
    }

    public function test_a_name_already_used_by_another_department_or_its_alias_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.catalogue.departments.store'), ['name' => 'البنوك والتمويل'])
            ->assertSessionHasErrors('name');
        $this->actingAs($admin)->put(route('admin.catalogue.departments.update', $this->department('labor')), ['name' => 'التأمين'])
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)->post(route('admin.catalogue.departments.store'), ['name' => 'قضايا الطيران'])
            ->assertSessionHasNoErrors();
        $this->assertSame('قضايا الطيران', LegalDepartment::query()->ordered()->get()->last()->name);
    }

    public function test_suspending_a_department_with_open_tickets_requires_confirmation(): void
    {
        $labor = $this->department('labor');
        $this->ticketIn($labor);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.catalogue.departments.toggle', $labor))->assertSessionHasErrors('confirm');
        $this->assertTrue($labor->fresh()->isActive());

        $this->actingAs($admin)->post(route('admin.catalogue.departments.toggle', $labor), ['confirm' => true])->assertSessionHasNoErrors();
        $this->assertFalse($labor->fresh()->isActive());
    }

    public function test_departments_can_be_reordered_but_not_with_a_partial_list(): void
    {
        $admin = $this->admin();
        $ids = LegalDepartment::query()->ordered()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->actingAs($admin)->post(route('admin.catalogue.departments.reorder'), ['order' => array_slice($ids, 1)])
            ->assertSessionHasErrors('order');

        $this->actingAs($admin)->post(route('admin.catalogue.departments.reorder'), ['order' => $reversed])->assertSessionHasNoErrors();
        $this->assertSame($reversed, LegalDepartment::query()->ordered()->pluck('id')->all());
    }

    public function test_renaming_an_administrative_department_follows_employees_only(): void
    {
        $staffDepartment = StaffDepartment::where('name', 'خدمة العملاء')->firstOrFail();
        $employee = User::factory()->create(['role' => Role::Employee, 'department' => 'خدمة العملاء']);

        $this->actingAs($this->admin())->put(route('admin.catalogue.staff-departments.update', $staffDepartment), ['name' => 'علاقات العملاء'])
            ->assertSessionHasNoErrors();

        $this->assertSame('علاقات العملاء', $employee->fresh()->department);
        $this->assertSame('علاقات العملاء', $staffDepartment->fresh()->name);
    }
}
