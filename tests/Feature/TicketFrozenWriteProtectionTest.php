<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketFrozenWriteProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Storage::fake();
    }

    public function test_employee_cannot_reply_attach_or_note_on_frozen_ticket(): void
    {
        [$ticket, $employee] = $this->frozenTicketWithActor(Role::Employee);
        $employee->syncPermissions(Permission::all());

        $this->actingAs($employee)
            ->postJson(route('employee.tickets.reply', $ticket), ['body' => 'محاولة رد بعد التجميد'])
            ->assertStatus(422);

        $this->actingAs($employee)
            ->postJson(route('employee.tickets.note', $ticket), ['body' => 'محاولة ملاحظة بعد التجميد'])
            ->assertStatus(422);

        $this->actingAs($employee)
            ->postJson(route('employee.tickets.attach', $ticket), [
                'file' => UploadedFile::fake()->create('after-freeze.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422);

        $this->assertSame(0, $ticket->messages()->count());
        $this->assertSame(0, $ticket->documents()->count());
        $this->assertSame(0, (int) $ticket->fresh()->attachments);
    }

    public function test_lawyer_cannot_reply_or_note_on_frozen_ticket(): void
    {
        [$ticket, $lawyer] = $this->frozenTicketWithActor(Role::Lawyer);
        $lawyer->syncPermissions(Permission::all());

        $this->actingAs($lawyer)
            ->postJson("/lawyer/tickets/{$ticket->number}/reply", ['body' => 'محاولة رد بعد التجميد'])
            ->assertStatus(422);

        $this->actingAs($lawyer)
            ->postJson("/lawyer/tickets/{$ticket->number}/note", ['body' => 'محاولة ملاحظة بعد التجميد'])
            ->assertStatus(422);

        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_admin_cannot_reply_or_note_on_frozen_ticket(): void
    {
        [$ticket, $admin] = $this->frozenTicketWithActor(Role::Admin);

        $this->actingAs($admin)
            ->postJson(route('admin.tickets.reply', $ticket), ['body' => 'محاولة رد إداري بعد التجميد'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson(route('admin.tickets.note', $ticket), ['body' => 'محاولة ملاحظة إدارية بعد التجميد'])
            ->assertStatus(422);

        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_employee_cannot_write_on_terminal_ticket_even_without_frozen_flag(): void
    {
        [$ticket, $employee] = $this->frozenTicketWithActor(Role::Employee, false);
        $employee->syncPermissions(Permission::all());

        $this->actingAs($employee)
            ->postJson(route('employee.tickets.reply', $ticket), ['body' => 'محاولة رد بعد الحالة النهائية'])
            ->assertStatus(422);

        $this->assertSame(0, $ticket->messages()->count());
    }

    /** @return array{0: Ticket, 1: User} */
    private function frozenTicketWithActor(Role $role, bool $frozen = true): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $actor = User::factory()->create(['role' => $role]);

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TKT-2026-'.uniqid(),
            'type' => 'استشارة تجارية',
            'subject' => 'اختبار حماية السجل',
            'details' => 'تذكرة اختبارية لحماية السجل بعد القرار النهائي.',
            'department' => 'القانون التجاري',
            'status' => TicketStatus::ConvertedToCase->value,
            'tone' => 'b-green',
            'is_frozen' => $frozen,
            'attachments' => 0,
        ]);

        if ($role === Role::Lawyer) {
            $ticket->forceFill([
                'assigned_lawyer_id' => $actor->id,
                'assigned_lawyer' => $actor->name,
            ])->save();
        }

        return [$ticket, $actor];
    }
}
