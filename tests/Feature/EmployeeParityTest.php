<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تماثل لوحة الموظف مع بقية الأدوار (الدفعة ب): تقويم المكتب، ومستندات القضية.
 */
class EmployeeParityTest extends TestCase
{
    use RefreshDatabase;

    private function case(User $client): LegalCase
    {
        return LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-P-1', 'type' => 'تجاري',
            'status' => 'منظورة', 'tone' => 'b-blue',
        ]);
    }

    // ── تقويم الموظف: كان غائباً تماماً بينما للمحامي تقويم كامل ──

    public function test_employee_calendar_lists_office_wide_events(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->case($client);
        CaseHearing::create([
            'case_id' => $case->id, 'title' => 'جلسة أولى', 'day' => '2026-09-01',
            'time' => '10:00', 'court' => 'المحكمة التجارية', 'status' => 'قادمة',
        ]);
        Meeting::create(['ref' => 'M-P-1', 'title' => 'اجتماع', 'when_label' => 'غد', 'status' => 'قادم']);

        $this->actingAs($employee)->get(route('employee.calendar'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('employee/calendar')->has('events', 2));
    }

    public function test_employee_without_scheduling_permission_is_redirected_from_calendar(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions([]); // بلا «جدولة المواعيد»

        $this->actingAs($employee)->get(route('employee.calendar'))->assertRedirect();
    }

    // ── مستندات قضية الموظف: كانت الصفحة الوحيدة بلا مستندات ولا إرفاق ──

    public function test_employee_case_page_exposes_documents(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->case($client);

        $this->actingAs($employee)->get(route('employee.cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('employee/case')->has('documents'));
    }

    public function test_employee_can_attach_document_to_case(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->case($client);

        $this->actingAs($employee)->post(route('employee.cases.attach', $case), [
            'file' => UploadedFile::fake()->create('مذكرة.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $this->assertDatabaseHas('case_documents', ['case_id' => $case->id, 'uploaded_by' => 'staff']);
    }

    public function test_employee_cannot_attach_to_archived_case(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->case($client);
        $case->update(['status' => 'مؤرشفة']);

        $this->actingAs($employee)->post(route('employee.cases.attach', $case), [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertStatus(422);
    }
}
