<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحقّق من اشتقاق رابط الشاشة المرتبطة بكل إشعار (جعل الإشعارات قابلة للنقر).
 */
class ClientNotificationLinkTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $u, string $body, string $icon = 'bell'): void
    {
        UserNotification::create([
            'user_id' => $u->id, 'icon' => $icon, 'tone' => 't-blue',
            'body' => $body, 'time_label' => 'الآن', 'is_read' => false,
        ]);
    }

    public function test_notifications_expose_derived_links(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        // أحدث أولاً (latest id) → رتّب الإدخال عكسيّاً لتوقّع الترتيب
        $this->notify($client, 'إشعار عامّ بلا شاشة مرتبطة');                 // → null
        $this->notify($client, 'وصلتك فاتورة INV-2026-5 بانتظار السداد');     // → /invoices
        $this->notify($client, 'تم تحديث التذكرة SB-2026-1042');              // → /tickets

        $links = collect(
            $this->actingAs($client)->get('/tickets')
                ->viewData('page')['props']['recentNotifications']
        )->pluck('link')->all();

        $this->assertSame(['/tickets', '/invoices', null], $links);
    }

    /**
     * **إشعار التنفيذ يفتح التنفيذ ولو ذُكرت فيه «أتعاب».** كان نمط القضايا يُفحص أوّلاً فتلتقط
     * كلمةُ «أتعاب» إشعارَ فاتورة أتعاب التنفيذ فيُفتح على القضايا. و`EX-` لم يطابق أرقامنا (EXE-…).
     */
    public function test_execution_fee_notifications_open_the_executions_screen(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->notify($client, 'صدرت فاتورة أتعاب التنفيذ لطلبك EXE-2026-0007 — بانتظار السداد.', 'card');

        $link = collect(
            $this->actingAs($client)->get('/tickets')
                ->viewData('page')['props']['recentNotifications']
        )->first()['link'];

        $this->assertSame('/execs', $link);
    }

    public function test_icon_fallback_maps_link(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->notify($client, 'تذكير بموعدك غداً', 'cal'); // نصّ فيه «موعد» → /appointments

        $link = collect(
            $this->actingAs($client)->get('/tickets')
                ->viewData('page')['props']['recentNotifications']
        )->first()['link'];

        $this->assertSame('/appointments', $link);
    }

    public function test_mark_all_notifications_as_read(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->notify($client, 'إشعار 1');
        $this->notify($client, 'إشعار 2');

        $this->assertDatabaseHas('user_notifications', ['user_id' => $client->id, 'is_read' => false]);

        $response = $this->actingAs($client)->post('/notifications/read-all');
        $response->assertStatus(302);

        $this->assertDatabaseMissing('user_notifications', ['user_id' => $client->id, 'is_read' => false]);
    }

    public function test_mark_single_notification_as_read(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->notify($client, 'إشعار مفرد');

        $notification = UserNotification::where('user_id', $client->id)->first();
        $this->assertFalse($notification->is_read);

        $response = $this->actingAs($client)->post("/notifications/{$notification->id}/read");
        $response->assertStatus(302);

        $this->assertTrue($notification->fresh()->is_read);
    }

    public function test_recent_notifications_shared_in_inertia(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->notify($client, 'تم تحديث التذكرة SB-2026-999');

        $response = $this->actingAs($client)->get('/tickets');
        $response->assertStatus(200);

        $recent = $response->viewData('page')['props']['recentNotifications'] ?? [];
        $this->assertNotEmpty($recent);
        $this->assertSame('/tickets', $recent[0]['link']);
        $this->assertFalse($recent[0]['is_read'] ?? ($recent[0]['unread'] === false));
    }
}
