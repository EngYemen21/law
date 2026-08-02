<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ConsultBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إشعارات نجاح الدفع: يصل العميل إشعارٌ («تمّ الدفع») وكل الإدارة العليا إشعارٌ («تمّت عملية دفع»)،
 * مرّة واحدة (idempotent). تُنشأ عبر Notify::send فتُبَثّ لحظياً على قناة كل مستخدم.
 */
class PaymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function pendingConsult(User $client): Consult
    {
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-NOTIF-1', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => 'مستشار', 'status' => 'بانتظار السداد', 'price' => 450, 'vat' => 68, 'total' => 518,
        ]);
        $consult->invoice()->create([
            'user_id' => $client->id, 'number' => 'INV-NOTIF-1', 'description' => 'استشارة CN-NOTIF-1',
            'amount' => 518, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 3 أيام', 'paid' => false,
        ]);

        return $consult->fresh();
    }

    public function test_payment_notifies_client_and_all_admins(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin1 = User::factory()->create(['role' => Role::Admin]);
        $admin2 = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->pendingConsult($client);

        ConsultBooking::markPaid($consult, 'ميسّر', 'مدفوع عبر ميسّر');

        // العميل: إشعار نجاح دفع (t-green)
        $this->assertSame(1, UserNotification::where('user_id', $client->id)->where('tone', 't-green')->count());
        // كل أدمن: إشعار دفع (t-green)
        $this->assertSame(1, UserNotification::where('user_id', $admin1->id)->where('tone', 't-green')->count());
        $this->assertSame(1, UserNotification::where('user_id', $admin2->id)->where('tone', 't-green')->count());
    }

    public function test_payment_notifications_are_idempotent(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->pendingConsult($client);

        ConsultBooking::markPaid($consult); // أول مرّة
        ConsultBooking::markPaid($consult->fresh()); // تكرار (webhook مزدوج) — لا أثر

        $this->assertSame(1, UserNotification::where('user_id', $client->id)->where('tone', 't-green')->count());
        $this->assertSame(1, UserNotification::where('user_id', $admin->id)->where('tone', 't-green')->count());
    }
}
