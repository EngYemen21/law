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
            $this->actingAs($client)->get('/notifications')
                ->viewData('page')['props']['notifications']
        )->pluck('link')->all();

        $this->assertSame(['/tickets', '/invoices', null], $links);
    }

    public function test_icon_fallback_maps_link(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->notify($client, 'تذكير بموعدك غداً', 'cal'); // نصّ فيه «موعد» → /appointments

        $link = collect(
            $this->actingAs($client)->get('/notifications')
                ->viewData('page')['props']['notifications']
        )->first()['link'];

        $this->assertSame('/appointments', $link);
    }
}
