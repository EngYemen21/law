<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عدّاد الإشعارات غير المقروءة — خاصية Inertia مشتركة تغذّي جرس الشريط العلوي
 * وشارة «الإشعارات» الجانبية. كان رقماً ثابتاً وهمياً (3) لا يتغيّر.
 */
class UnreadNotificationsBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, bool $read): UserNotification
    {
        return UserNotification::create([
            'user_id' => $user->id, 'icon' => 'bell', 'tone' => 't-blue',
            'body' => 'إشعار', 'time_label' => 'الآن', 'is_read' => $read,
        ]);
    }

    public function test_shared_prop_reflects_real_unread_count(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->notify($client, read: false);
        $this->notify($client, read: false);
        $this->notify($client, read: true); // مقروء لا يُحسب

        $this->actingAs($client)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn ($p) => $p->where('unreadNotifications', 2));
    }

    public function test_count_is_zero_when_all_read_or_none(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->notify($client, read: true);

        $this->actingAs($client)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn ($p) => $p->where('unreadNotifications', 0));
    }

    public function test_mark_all_read_clears_the_count(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->notify($client, read: false);
        $this->notify($client, read: false);

        $this->actingAs($client)->post(route('notifications.read-all'))->assertRedirect();

        $this->actingAs($client)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn ($p) => $p->where('unreadNotifications', 0));
    }

    public function test_count_is_scoped_per_user(): void
    {
        $a = User::factory()->create(['role' => Role::Client]);
        $b = User::factory()->create(['role' => Role::Client]);
        $this->notify($a, read: false);
        $this->notify($b, read: false);
        $this->notify($b, read: false);

        $this->actingAs($a)->get(route('dashboard'))
            ->assertOk()->assertInertia(fn ($p) => $p->where('unreadNotifications', 1));
    }
}
