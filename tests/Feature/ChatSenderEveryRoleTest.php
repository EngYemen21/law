<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseMessage;
use App\Models\Execution;
use App\Models\ExecutionMessage;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **كلّ رسالةٍ في المحادثات الثلاث تُحفظ كاملةً بحساب مرسلها وعنوانه — لكلّ دورٍ يكتب فيها** (تدقيق
 * 2026-09-29، طلب المالك). يمرّ كلُّ دورٍ بمسار ردّه الحقيقيّ: العميل والموظّف والمحامي والإدارة.
 */
class ChatSenderEveryRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_message_keeps_full_body_sender_and_ip(): void
    {
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر', 'الرد على العملاء', 'إدارة القضايا والأتعاب'])->get());
        $admin = User::factory()->create(['role' => Role::Admin]);

        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-SND-1', 'type' => 'نزاع تجاري', 'department' => 'القسم التجاري',
            'status' => 'قيد التحليل', 'tone' => 'b-blue', 'assigned_lawyer_id' => $lawyer->id,
        ]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'number' => 'CASE-SND-9', 'type' => 'نزاع تجاري',
            'status' => 'منظورة', 'tone' => 'b-blue', 'update_text' => '—', 'assigned_lawyer_id' => $lawyer->id,
        ]);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-SND-1', 'subject' => 'تنفيذ', 'status' => 'قيد التنفيذ',
            'tone' => 'b-blue', 'stage' => 8, 'assigned_lawyer_id' => $lawyer->id,
        ]);

        $long = str_repeat('نصٌّ طويل للتحقّق من الحفظ الكامل. ', 60);
        $sends = [
            // [المرسِل، المسار، المالك، نموذج الرسالة، IP]
            [$client, route('tickets.messages.store', $ticket), TicketMessage::class, '198.51.100.1'],
            [$employee, route('employee.tickets.reply', $ticket), TicketMessage::class, '198.51.100.2'],
            [$lawyer, route('lawyer.tickets.reply', $ticket), TicketMessage::class, '198.51.100.3'],
            [$admin, route('admin.tickets.reply', $ticket), TicketMessage::class, '198.51.100.4'],
            [$client, route('cases.messages.store', $case), CaseMessage::class, '198.51.100.5'],
            [$employee, route('employee.cases.reply', $case), CaseMessage::class, '198.51.100.6'],
            [$lawyer, route('lawyer.cases.reply', $case), CaseMessage::class, '198.51.100.7'],
            [$client, route('exec-flow.messages.store', $exec), ExecutionMessage::class, '198.51.100.8'],
            [$employee, route('exec-flow.messages.store', $exec), ExecutionMessage::class, '198.51.100.9'],
            [$lawyer, route('exec-flow.messages.store', $exec), ExecutionMessage::class, '198.51.100.10'],
            [$admin, route('exec-flow.messages.store', $exec), ExecutionMessage::class, '198.51.100.11'],
        ];

        foreach ($sends as $i => [$user, $url, $model, $ip]) {
            $body = "رسالة {$i} — {$long}";
            $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post($url, ['body' => $body, 'kind' => 'reply'])->assertSuccessful();

            $row = $model::where('body', 'like', "%رسالة {$i} —%")->first();
            $this->assertNotNull($row, "رسالة {$i} ({$user->role->value}) حُفظت");
            $this->assertSame($user->id, $row->sender_id, "رسالة {$i}: المرسِل");
            $this->assertSame($ip, $row->sender_ip, "رسالة {$i}: العنوان");
            $this->assertStringContainsString('للتحقّق من الحفظ الكامل', strip_tags((string) $row->body));
            $this->assertGreaterThan(2000, mb_strlen(strip_tags((string) $row->body)), "رسالة {$i}: النصّ كاملاً");
        }
    }
}
