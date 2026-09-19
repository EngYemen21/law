<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalDepartment;
use App\Models\User;
use App\Support\LegalCatalogue;
use App\Support\Specialties;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * نموذج الطاقم: تخصّصات المحامي المتعدّدة من كتالوج الأقسام، وقسم الموظّف من الأقسام الإداريّة.
 */
class AdminStaffSpecialtiesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function deptId(string $code): int
    {
        return LegalCatalogue::department($code)->id;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'أ. سالم', 'role' => 'lawyer', 'job_title' => 'محامٍ', 'email' => 'salem@office.test',
            'mobile' => '0551230001', 'nid' => '1122330001', 'payType' => 'salary', 'salary' => 1000, 'perms' => [],
        ];
    }

    public function test_the_staff_screen_receives_both_department_lists(): void
    {
        $this->actingAs($this->admin())->get(route('admin.staff'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('legalDepartments', LegalCatalogue::departments()->count())
                ->where('staffDepartments', ['خدمة العملاء', 'الإدارة المالية']));
    }

    public function test_a_lawyer_is_saved_with_several_specialties(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.staff.store'), $this->payload([
            'specialties' => [$this->deptId('labor'), $this->deptId('insurance')],
        ]))->assertSessionHasNoErrors();

        $lawyer = User::where('email', 'salem@office.test')->firstOrFail();
        $this->assertEqualsCanonicalizing([$this->deptId('labor'), $this->deptId('insurance')], $lawyer->specialties->pluck('id')->all());
        $this->assertSame('القضايا العمالية', $lawyer->department, 'نسخة العرض = أوّل تخصّص مختار.');
        $this->assertFalse($lawyer->covers_all_departments);
    }

    public function test_switching_a_lawyer_to_cover_all_departments_clears_the_list(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.staff.store'), $this->payload(['specialties' => [$this->deptId('labor')]]));
        $lawyer = User::where('email', 'salem@office.test')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.staff.update', $lawyer), $this->payload(['coversAll' => true]))
            ->assertSessionHasNoErrors();

        $lawyer->refresh();
        $this->assertTrue($lawyer->covers_all_departments);
        $this->assertCount(0, $lawyer->specialties);
        $this->assertSame(Specialties::ALL_DEPARTMENTS, $lawyer->department);
    }

    public function test_a_suspended_specialty_is_rejected(): void
    {
        LegalDepartment::whereKey($this->deptId('labor'))->update(['status' => 'suspended']);
        LegalCatalogue::flush();

        $this->actingAs($this->admin())->post(route('admin.staff.store'), $this->payload([
            'specialties' => [$this->deptId('labor')],
        ]))->assertSessionHasErrors('specialties.0');
    }

    public function test_an_employee_department_must_come_from_the_administrative_list(): void
    {
        $admin = $this->admin();
        $employee = $this->payload(['role' => 'employee', 'job_title' => 'موظف', 'email' => 'emp@office.test', 'mobile' => '0551230002', 'nid' => '1122330002']);

        $this->actingAs($admin)->post(route('admin.staff.store'), $employee + ['dept' => 'القضايا العمالية'])
            ->assertSessionHasErrors('dept');

        $this->actingAs($admin)->post(route('admin.staff.store'), $employee + ['dept' => 'خدمة العملاء'])
            ->assertSessionHasNoErrors();
        $this->assertSame('خدمة العملاء', User::where('email', 'emp@office.test')->value('department'));
    }

    public function test_a_legacy_employee_department_stays_editable(): void
    {
        $admin = $this->admin();
        $legacy = User::factory()->create([
            'role' => Role::Employee, 'department' => 'قسمٌ قديم', 'email' => 'old@office.test',
            'phone' => '0551230003', 'national_id' => '1122330003',
        ]);

        $this->actingAs($admin)->put(route('admin.staff.update', $legacy), $this->payload([
            'role' => 'employee', 'job_title' => 'موظف', 'email' => 'old@office.test', 'mobile' => '0551230003',
            'nid' => '1122330003', 'dept' => 'قسمٌ قديم',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('قسمٌ قديم', $legacy->fresh()->department);
    }
}
