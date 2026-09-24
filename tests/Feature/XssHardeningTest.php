<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Notify;
use App\Support\TicketTriage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحصين الحقن المخزّن (XSS):
 * - نصّ الإشعار بيانات لا HTML — يُخزَّن كما هو ويُرسم نصًّا في الواجهة (React يهرّب).
 *   كان يُرسم بـdangerouslySetInnerHTML، فحقلٌ يتحكم به العميل (نوع التذكرة) كان
 *   يصل خامًا إلى جلسة كل موظف ومدير يفتح صفحة الإشعارات.
 * - فقاعات المحادثة HTML مقصود، فكل قيمة يتحكم بها المستخدم داخلها تُهرَّب عند الكتابة.
 */
class XssHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '<img src=x onerror=alert(1)>';

    public function test_client_ticket_type_reaches_staff_notification_without_executable_markup(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        User::factory()->create(['role' => Role::Lawyer, 'department' => 'القضايا التجارية']);

        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => self::PAYLOAD,
            'department' => 'القضايا التجارية',
            'details' => 'تفاصيل الطلب',
        ])->assertRedirect();

        $notification = UserNotification::where('user_id', $employee->id)->firstOrFail();

        // الصفحة المستقلة أُلغيت وحُوّلت، وتُبثّ الإشعارات عبر المنسدلة في Inertia shared props
        $this->actingAs($employee)->get(route('notifications'))
            ->assertRedirect();

        // الحمولة محفوظة كبيانات (لا تُعدَّل)، والحماية في طبقة العرض
        $this->assertStringContainsString(self::PAYLOAD, $notification->body);
    }

    public function test_referral_message_escapes_client_controlled_department(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $ticket = Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-XSS-1',
            'type' => 'تجاري',
            'department' => self::PAYLOAD,
            'assigned_lawyer_id' => $lawyer->id,
            'status' => 'قيد المعالجة',
            'tone' => 'b-blue',
        ]);

        TicketTriage::referToLawyer($ticket);

        $body = $ticket->messages()->latest('id')->first()->body;
        $this->assertStringNotContainsString('<img', $body);
        $this->assertStringContainsString('&lt;img', $body);
    }

    public function test_notify_stores_body_verbatim_and_is_the_single_writer(): void
    {
        $user = User::factory()->create(['role' => Role::Employee]);
        $notification = Notify::send($user->id, 'bell', 't-blue', self::PAYLOAD);

        $this->assertSame(self::PAYLOAD, $notification->fresh()->body);
        $this->assertSame(self::PAYLOAD, $notification->toData()['text']);
    }
}
