<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Mail\TicketOpenedMail;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * تنبيهات فتح التذكرة — كانت التذكرة الجديدة تصل صامتة: لا إشعار داخلياً للموظف/الإدارة
 * ولا بريد تأكيد للعميل. الآن: إشعار داخلي لموظفي فرع التذكرة والإدارة العليا + بريد للثلاثة.
 */
class TicketOpenNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_open_notifies_branch_staff_admins_and_mails_everyone(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض', 'department' => 'القسم التجاري']);
        $branchEmployee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $otherEmployee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع جدة']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => 'القسم التجاري', 'details' => 'أطالب بمستحقاتي.',
        ])->assertRedirect();

        $ticket = Ticket::latest('id')->firstOrFail();
        $this->assertSame('فرع الرياض', $ticket->branch); // خُتمت بفرع المحامي المسند

        // إشعار داخلي: موظف فرع التذكرة + الإدارة العليا — وموظف الفرع الآخر معزول
        $this->assertTrue(UserNotification::where('user_id', $branchEmployee->id)->where('body', 'like', "%{$ticket->number}%")->exists());
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('body', 'like', "%{$ticket->number}%")->exists());
        $this->assertFalse(UserNotification::where('user_id', $otherEmployee->id)->exists());

        // بريد: العميل (تأكيد استلام) + موظف الفرع + الإدارة — كلٌّ بنسخته
        Mail::assertQueued(TicketOpenedMail::class, fn ($m) => $m->audience === 'client' && $m->hasTo($client->email));
        Mail::assertQueued(TicketOpenedMail::class, fn ($m) => $m->audience === 'employee' && $m->hasTo($branchEmployee->email));
        Mail::assertQueued(TicketOpenedMail::class, fn ($m) => $m->audience === 'admin' && $m->hasTo($admin->email));
        Mail::assertNotQueued(TicketOpenedMail::class, fn ($m) => $m->hasTo($otherEmployee->email));

        // تصيير فعلي للقالب بنسختيه (Mail::fake لا يبني المحتوى — خطأ blade كان سيفلت)
        $clientHtml = (new TicketOpenedMail($ticket->fresh(), 'client'))->render();
        $this->assertStringContainsString($ticket->number, $clientHtml);
        $this->assertStringContainsString('تم استلام طلبكم', $clientHtml);
        $staffHtml = (new TicketOpenedMail($ticket->fresh(), 'employee'))->render();
        $this->assertStringContainsString('تذكرة جديدة بانتظار المتابعة', $staffHtml);
        $this->assertStringContainsString('/employee/tickets', $staffHtml);
    }

    public function test_suspended_employee_is_not_notified(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $suspended = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض', 'status' => 'suspended']);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'details' => 'طلب.',
        ])->assertRedirect();

        $this->assertFalse(UserNotification::where('user_id', $suspended->id)->exists());
        Mail::assertNotQueued(TicketOpenedMail::class, fn ($m) => $m->hasTo($suspended->email));
    }
}
