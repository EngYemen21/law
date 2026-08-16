<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * إرفاق الموظف مستنداً بالتذكرة — كان الرفع حصراً للعميل (لا مسار للموظف إطلاقاً).
 */
class EmployeeTicketAttachTest extends TestCase
{
    use RefreshDatabase;

    private function ticketWithEmployee(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-7001',
            'type' => 'استشارة قانونية',
            'department' => 'القانون التجاري',
            'status' => 'قيد التحليل',
            'tone' => 'b-blue',
            'branch' => 'الرياض',
        ]);
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'الرياض']);

        return [$ticket, $employee];
    }

    public function test_employee_can_attach_document_to_ticket(): void
    {
        Storage::fake();
        [$ticket, $employee] = $this->ticketWithEmployee();

        $this->actingAs($employee)
            ->post(route('employee.tickets.attach', $ticket), [
                'file' => UploadedFile::fake()->create('عقد_العمل.pdf', 120, 'application/pdf'),
            ])
            ->assertNoContent();

        $ticket->refresh();
        $this->assertSame(1, $ticket->attachments);
        $this->assertSame(1, $ticket->documents()->count());
        $this->assertTrue($ticket->messages->contains(
            fn ($m) => $m->who === 'staff' && str_contains($m->body, 'عقد_العمل.pdf')
        ));
    }

    public function test_attach_rejects_disallowed_extension(): void
    {
        Storage::fake();
        [$ticket, $employee] = $this->ticketWithEmployee();

        $this->actingAs($employee)
            ->postJson(route('employee.tickets.attach', $ticket), [
                'file' => UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream'),
            ])
            ->assertStatus(422);

        $this->assertSame(0, $ticket->fresh()->attachments);
    }

    public function test_attach_requires_reply_permission(): void
    {
        Storage::fake();
        [$ticket, $employee] = $this->ticketWithEmployee();
        // المصنع يمنح كل الصلاحيات — نجرّدها من «الرد على العملاء»
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر'])->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($employee)
            ->postJson(route('employee.tickets.attach', $ticket), [
                'file' => UploadedFile::fake()->create('عقد.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(403);

        $this->assertSame(0, $ticket->fresh()->attachments);
    }
}
