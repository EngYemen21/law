<?php

namespace Tests\Feature;

use App\Support\Live;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Broadcast;
use RuntimeException;
use Tests\TestCase;

/**
 * **Reverb متوقّف لا يُسقط طلب المستخدم.** (قيسَ في المتصفّح 2026-09-11)
 *
 * كلّ بثٍّ `ShouldBroadcastNow` يُرسَل داخل الطلب نفسه، وكان كلّ حدثٍ ينتظر ~٢٫٣ث حتى يفشل
 * الاتصال. فتسجيلُ قيد دعوى (رسالة + إشعار + حالة، مرّتين مع الجلسة الأولى) تجاوز مهلة ٣٠ث،
 * فعاد ٥٠٠ بعد أن كُتبت البيانات كلّها — والشاشة بقيت على حالها القديمة.
 */
class LiveBroadcastResilienceTest extends TestCase
{
    public static int $attempts = 0;

    private function failingBroadcaster(): void
    {
        self::$attempts = 0;

        Broadcast::extend('failing', fn () => new class extends Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                LiveBroadcastResilienceTest::$attempts++;

                throw new RuntimeException('cURL error 7: Failed to connect to localhost port 8080');
            }
        });

        config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
    }

    private function event(): object
    {
        return new class implements ShouldBroadcastNow
        {
            public function broadcastOn(): array
            {
                return [new Channel('case.1')];
            }
        };
    }

    public function test_a_dead_broadcaster_is_tried_once_per_request_not_once_per_event(): void
    {
        $this->failingBroadcaster();

        // ستّة أحداثٍ كما في تسجيل القيد — لا تُرمى، ولا تُكرَّر المحاولة بعد الفشل الأوّل
        Live::push($this->event(), $this->event(), $this->event());
        Live::push($this->event(), $this->event(), $this->event());

        $this->assertSame(1, self::$attempts, 'قاطع الدائرة: محاولةٌ واحدة ثم تُتخطّى البقيّة');
    }

    public function test_the_reverb_client_has_short_timeouts(): void
    {
        $options = config('broadcasting.connections.reverb.client_options');

        $this->assertLessThanOrEqual(2, $options['connect_timeout'] ?? 99, 'مهلة الاتصال قصيرة');
        $this->assertLessThanOrEqual(5, $options['timeout'] ?? 99);
    }
}
