<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LawyerTicketReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_lawyer_can_reply_to_client_on_assigned_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'branch' => 'فرع الرياض']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار عبد العزيز', 'branch' => 'فرع الرياض']);
        $lawyer->syncPermissions(Permission::all());

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TKT-2026-0001',
            'type' => 'استشارة تجارية',
            'subject' => 'استشارة تجارية عاجلة',
            'details' => 'تفاصيل الاستشارة',
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
            'branch' => 'فرع الرياض',
            'status' => 'بانتظار دراسة المستشار',
            'tone' => 'b-amber',
        ]);

        $response = $this->actingAs($lawyer)->post("/lawyer/tickets/{$ticket->number}/reply", [
            'body' => 'تمت مراجعة ملف القضية وتفاصيل المستندات، وسنقوم برفع المذكرة الجوابية.',
        ]);

        $response->assertNoContent();

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'who' => 'lawyer',
            'name' => 'المستشار عبد العزيز',
            'role' => 'المستشار القانوني',
        ]);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'last_message' => 'تمت مراجعة ملف القضية وتفاصيل المستندات، وسنقوم برفع المذكرة الجوابية.',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $client->id,
            'icon' => 'ticket',
        ]);
    }

    public function test_lawyer_can_add_internal_note_on_assigned_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'branch' => 'فرع الرياض']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار عبد العزيز', 'branch' => 'فرع الرياض']);
        $lawyer->syncPermissions(Permission::all());

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TKT-2026-0002',
            'type' => 'استشارة تجارية',
            'subject' => 'استشارة تجارية عاجلة',
            'details' => 'تفاصيل الاستشارة',
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
            'branch' => 'فرع الرياض',
            'status' => 'بانتظار دراسة المستشار',
            'tone' => 'b-amber',
        ]);

        $response = $this->actingAs($lawyer)->post("/lawyer/tickets/{$ticket->number}/note", [
            'body' => 'ملاحظة سرية: السند لأمر يحتاج مطابقة مع كشف الحساب البنكي.',
        ]);

        $response->assertNoContent();

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'who' => 'note',
            'name' => 'المستشار عبد العزيز',
            'role' => 'ملاحظة مستشار',
        ]);
    }

    public function test_lawyer_cannot_reply_or_note_on_ticket_assigned_to_another_lawyer(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'branch' => 'فرع الرياض']);
        $lawyer1 = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار 1', 'branch' => 'فرع الرياض']);
        $lawyer2 = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المستشار 2', 'branch' => 'فرع الرياض']);
        $lawyer2->syncPermissions(Permission::all());

        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TKT-2026-0003',
            'type' => 'استشارة تجارية',
            'subject' => 'استشارة تجارية عاجلة',
            'details' => 'تفاصيل الاستشارة',
            'assigned_lawyer_id' => $lawyer1->id,
            'assigned_lawyer' => $lawyer1->name,
            'branch' => 'فرع الرياض',
            'status' => 'بانتظار دراسة المستشار',
            'tone' => 'b-amber',
        ]);

        $this->actingAs($lawyer2)->post("/lawyer/tickets/{$ticket->number}/reply", [
            'body' => 'محاولة اختراق عزل المحامي',
        ])->assertStatus(403);

        $this->actingAs($lawyer2)->post("/lawyer/tickets/{$ticket->number}/note", [
            'body' => 'محاولة اختراق عزل المحامي',
        ])->assertStatus(403);
    }

    public function test_admin_can_reply_and_note_on_any_ticket(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'name' => 'مدير النظام', 'branch' => 'فرع الرياض']);
        $client = User::factory()->create(['role' => Role::Client, 'branch' => 'فرع الرياض']);
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'TKT-2026-0004',
            'type' => 'استشارة تجارية',
            'subject' => 'استشارة تجارية عاجلة',
            'details' => 'تفاصيل الاستشارة',
            'branch' => 'فرع الرياض',
            'status' => 'جديدة',
            'tone' => 'b-amber',
        ]);

        $this->actingAs($admin)->post("/admin/tickets/{$ticket->number}/reply", [
            'body' => 'رد من إدارة المكتب للعميل مباشرة.',
        ])->assertNoContent();

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'who' => 'lawyer',
            'name' => 'مدير النظام',
            'role' => 'الإدارة',
        ]);

        $this->actingAs($admin)->post("/admin/tickets/{$ticket->number}/note", [
            'body' => 'ملاحظة إدارية داخلية.',
        ])->assertNoContent();

        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'who' => 'note',
            'name' => 'مدير النظام',
            'role' => 'ملاحظة إدارة',
        ]);
    }
}
