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
 * ولا بريد تأكيد للعميل. الآن: إشعار داخلي لموظفي المكتب والإدارة العليا + بريد للثلاثة.
 */
class TicketOpenNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_open_notifies_all_employees_admins_and_mails_everyone(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client]);
        User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $secondEmployee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => 'القسم التجاري', 'details' => 'أطالب بمستحقاتي.',
        ])->assertRedirect();

        $ticket = Ticket::latest('id')->firstOrFail();

        // إشعار داخلي: كل موظفي المكتب + الإدارة العليا (مكتب واحد بلا فروع)
        $this->assertTrue(UserNotification::where('user_id', $employee->id)->where('body', 'like', "%{$ticket->number}%")->exists());
        $this->assertTrue(UserNotification::where('user_id', $secondEmployee->id)->where('body', 'like', "%{$ticket->number}%")->exists());
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('body', 'like', "%{$ticket->number}%")->exists());

        // بريد: العميل (تأكيد استلام) + الموظفون + الإدارة — كلٌّ بنسخته
        Mail::assertQueued(TicketOpenedMail::class, fn ($m) => $m->audience === 'client' && $m->hasTo($client->email));
        Mail::assertQueued(TicketOpenedMail::class, fn ($m) => $m->audience === 'employee' && $m->hasTo($employee->email));
        Mail::assertQueued(TicketOpenedMail::class, fn ($m) => $m->audience === 'employee' && $m->hasTo($secondEmployee->email));
        Mail::assertQueued(TicketOpenedMail::class, fn ($m) => $m->audience === 'admin' && $m->hasTo($admin->email));

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
        User::factory()->create(['role' => Role::Lawyer]);
        $suspended = User::factory()->create(['role' => Role::Employee, 'status' => 'suspended']);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'details' => 'طلب.',
        ])->assertRedirect();

        $this->assertFalse(UserNotification::where('user_id', $suspended->id)->exists());
        Mail::assertNotQueued(TicketOpenedMail::class, fn ($m) => $m->hasTo($suspended->email));
    }
}
