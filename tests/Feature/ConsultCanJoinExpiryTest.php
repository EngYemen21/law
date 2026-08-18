<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سقف زمني لزر «دخول الجلسة» + وسم «فائتة» للعميل — كان canJoin بلا حدّ علوي
 * فيبقى الزر مفعّلاً للأبد بعد فوات موعد لم تُعقد جلسته، وكانت «بانتظار الجلسة» أبدية.
 */
class ConsultCanJoinExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function consult(array $extra = []): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create(array_merge([
            'user_id' => $client->id,
            'ref' => 'CN-'.uniqid(),
            'subject' => 'نزاع تجاري',
            'channel' => 'مرئية',
            'lawyer' => 'أ. سارة',
            'day' => 'اليوم',
            'time' => '10:00',
            'when_label' => 'اليوم · 10:00',
            'session' => 'بانتظار الجلسة',
            'duration_min' => 45,
        ], $extra));
    }

    public function test_released_link_within_window_can_join(): void
    {
        $c = $this->consult(['starts_at' => now()->addMinutes(3), 'link_released_at' => now()]);

        $this->assertTrue($c->canJoin());
        $this->assertFalse($c->isMissed());
    }

    public function test_released_link_expires_after_window(): void
    {
        // أُطلق الرابط ثم فات الموعد بلا جلسة — كان الزر يبقى مفعّلاً للأبد
        $c = $this->consult(['starts_at' => now()->subHours(2), 'link_released_at' => now()->subHours(2)]);

        $this->assertFalse($c->canJoin());
        $this->assertTrue($c->isMissed());
        $this->assertTrue($c->toClientCard()['missed']);
    }

    public function test_running_session_can_join_within_cap_only(): void
    {
        $running = $this->consult(['session' => 'جلسة جارية', 'starts_at' => now()->subMinutes(90)]);
        $this->assertTrue($running->canJoin());

        $stuck = $this->consult(['session' => 'جلسة جارية', 'starts_at' => now()->subHours(5)]);
        $this->assertFalse($stuck->canJoin()); // «جارية» عالقة — لا دخول أبدياً
    }

    public function test_ended_session_never_joins_and_not_missed(): void
    {
        $c = $this->consult(['session' => 'منتهية', 'starts_at' => now()->subHours(2), 'link_released_at' => now()->subHours(2)]);

        $this->assertFalse($c->canJoin());
        $this->assertFalse($c->isMissed()); // انعقدت فعلاً — ليست فائتة
    }

    public function test_unreleased_link_still_blocked_before_start(): void
    {
        // سلوك الإطلاق المثبّت في ZoomLifecycleTest محفوظ: قبل الإطلاق الزر معطّل حتى داخل النافذة
        $c = $this->consult(['starts_at' => now()->addMinutes(3), 'link_released_at' => null]);

        $this->assertFalse($c->canJoin());
    }
}
