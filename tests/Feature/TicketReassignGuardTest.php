<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **لا يُعاد إسناد تذكرةٍ مجمّدة أو نهائيّة — من الموظّف ولا من الإدارة** (قرار المالك 2026-09-30).
 *
 * ثبت في المتصفّح: تحويل الموظّف كان بلا حارس، فصار لتذكرةٍ مغلقة (SB-2026-4316) ولأخرى محوّلةٍ لقضيّة
 * وتنفيذ محامٍ جديد؛ وحارس الإدارة يُسنِد «مكتملة». وكلا المسارين لا يُعلم المحامي بما أُسند إليه.
 */
class TicketReassignGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $admin;

    private User $lawyer;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->employee = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $this->employee->syncPermissions(Permission::whereIn('name', ['تحويل التذاكر'])->get());
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->client = User::factory()->create(['role' => Role::Client]);
    }

    private function ticket(string $status, bool $frozen = false): Ticket
    {
        return Ticket::create([
            'user_id' => $this->client->id, 'number' => 'SB-RG-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => $status, 'tone' => 'b-grey', 'is_frozen' => $frozen,
        ]);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function lockedTickets(): array
    {
        return [
            'مجمّدة' => ['قيد التحليل', true],
            'مغلقة' => ['مغلقة', true],
            'محوّلة لقضيّة' => ['محولة إلى قضية', true],
            'محوّلة لتنفيذ' => ['محولة إلى تنفيذ', true],
            'مكتملة' => ['مكتملة', false],
        ];
    }

    #[DataProvider('lockedTickets')]
    public function test_the_employee_cannot_transfer_a_locked_ticket(string $status, bool $frozen): void
    {
        $ticket = $this->ticket($status, $frozen);

        $this->actingAs($this->employee)->postJson(route('employee.transfer.do', $ticket), ['lawyer_id' => $this->lawyer->id])
            ->assertStatus(422);

        $this->assertNull($ticket->fresh()->assigned_lawyer_id);
        $this->assertSame(0, UserNotification::where('user_id', $this->lawyer->id)->count());
    }

    #[DataProvider('lockedTickets')]
    public function test_the_admin_cannot_assign_a_locked_ticket(string $status, bool $frozen): void
    {
        $ticket = $this->ticket($status, $frozen);

        $this->actingAs($this->admin)->post(route('admin.distribute.assign', $ticket), ['lawyer_id' => $this->lawyer->id])
            ->assertStatus(422);

        $this->assertNull($ticket->fresh()->assigned_lawyer_id);
    }

    /** زرّ «تحويل» يقرأ علَم الخادم نفسه الذي يقيسه الحارس — كان يقيس `isTerminal` فيظهر على «مكتملة». */
    #[DataProvider('lockedTickets')]
    public function test_the_card_flag_hides_transfer_on_a_locked_ticket(string $status, bool $frozen): void
    {
        $this->assertFalse($this->ticket($status, $frozen)->toEmployeeCard()['isReassignable']);
    }

    public function test_the_card_flag_allows_transfer_on_an_open_ticket_and_the_ui_reads_it(): void
    {
        $this->assertTrue($this->ticket('قيد التحليل')->toEmployeeCard()['isReassignable']);

        $list = (string) file_get_contents(resource_path('js/pages/employee/tickets.tsx'));
        $this->assertStringNotContainsString('!t.isTerminal && !t.isFrozen', $list);
        $this->assertStringContainsString('t.isReassignable', $list);
        $this->assertStringContainsString('ticket.isReassignable', (string) file_get_contents(resource_path('js/pages/employee/ticketchat.tsx')));
        // الحارس يسأل النموذج (`isOpen`) لا نسخةً من قائمة الحالات النهائيّة
        $this->assertStringNotContainsString('TicketStatus::finals()', (string) file_get_contents(app_path('Support/TicketAssignment.php')));
    }

    public function test_bulk_transfer_moves_the_open_tickets_and_names_the_refused(): void
    {
        $open = $this->ticket('قيد التحليل');
        $closed = $this->ticket('مغلقة', true);

        $this->actingAs($this->employee)->post(route('employee.transfer.bulk'), [
            'tickets' => [$open->number, $closed->number],
            'lawyer_id' => $this->lawyer->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($this->lawyer->id, $open->fresh()->assigned_lawyer_id);
        $this->assertNull($closed->fresh()->assigned_lawyer_id);
        // نجاحٌ جزئيّ لا خطأ: الواجهة تُفرغ التحديد (فلا يُعاد تحويل ما حُوِّل)، والمرفوضة تُذكر بسببها
        $this->assertStringContainsString('تم تحويل 1 تذكرة', (string) session('flash'));
        $this->assertStringContainsString($closed->number, (string) session('error'));

        $notice = UserNotification::where('user_id', $this->lawyer->id)->sole();
        $this->assertStringContainsString($open->number, $notice->body);
        $this->assertStringNotContainsString($closed->number, $notice->body);
    }

    public function test_bulk_transfer_of_only_locked_tickets_is_refused(): void
    {
        $closed = $this->ticket('مغلقة', true);

        $this->actingAs($this->employee)->postJson(route('employee.transfer.bulk'), [
            'tickets' => [$closed->number],
            'lawyer_id' => $this->lawyer->id,
        ])->assertStatus(422);

        $this->assertNull($closed->fresh()->assigned_lawyer_id);
    }

    public function test_the_new_lawyer_is_notified_on_transfer_and_on_assignment(): void
    {
        $viaEmployee = $this->ticket('قيد التحليل');
        $viaAdmin = $this->ticket('قيد التحليل');

        $this->actingAs($this->employee)->postJson(route('employee.transfer.do', $viaEmployee), ['lawyer_id' => $this->lawyer->id])->assertOk();
        $this->actingAs($this->admin)->post(route('admin.distribute.assign', $viaAdmin), ['lawyer_id' => $this->lawyer->id])->assertRedirect();

        $bodies = UserNotification::where('user_id', $this->lawyer->id)->pluck('body')->implode(' | ');
        $this->assertStringContainsString("أُسندت إليك التذكرة {$viaEmployee->number}", $bodies);
        $this->assertStringContainsString("أُسندت إليك التذكرة {$viaAdmin->number}", $bodies);
    }

    public function test_the_transfer_desk_lists_no_frozen_ticket(): void
    {
        $this->ticket('قيد التحليل');
        $this->ticket('قيد التحليل', true);

        $this->actingAs($this->employee)->get('/employee/transfer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('employee/transfer')->has('tickets', 1));
    }
}
