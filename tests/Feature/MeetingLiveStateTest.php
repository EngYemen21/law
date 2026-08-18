<?php

namespace Tests\Feature;

use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الاجتماعات: الحالة المخزّنة لا تتحدّث بمرور الوقت — liveState يشتق الحقيقة عند القراءة
 * (نظير Appointment::liveState): «قادم» الفائت = «لم ينعقد» فوراً بلا مجدول، «جارٍ» المتجاوز
 * لمدّته = منتهٍ، «بانتظار التأكيد»/«مؤجل» قادمة، وزر الدخول بنافذة مغلقة الطرفين.
 */
class MeetingLiveStateTest extends TestCase
{
    use RefreshDatabase;

    private function meeting(array $extra = []): Meeting
    {
        return Meeting::create(array_merge([
            'ref' => 'M-'.uniqid(),
            'title' => 'اجتماع اختبار',
            'when_label' => 'اليوم · 10:00 ص',
            'status' => 'قادم',
            'dur' => '60 دقيقة',
        ], $extra));
    }

    public function test_future_upcoming_meeting_stays_upcoming(): void
    {
        $m = $this->meeting(['starts_at' => now()->addDay()]);

        $this->assertSame(['up', 'قادم', 'b-blue'], $m->liveState());
        $this->assertTrue($m->isUpcoming());
        $this->assertFalse($m->canJoin()); // قبل نافذة الـ5 دقائق
    }

    public function test_pending_confirmation_counts_as_upcoming(): void
    {
        // كانت «بانتظار التأكيد» يتيمة: اجتماع مستقبلي يسقط فوراً في «السابقة»
        $m = $this->meeting(['status' => 'بانتظار التأكيد', 'starts_at' => now()->addDays(2)]);

        $this->assertSame(['up', 'بانتظار التأكيد', 'b-amber'], $m->liveState());
        $this->assertTrue($m->toCard()['up']);
    }

    public function test_postponed_counts_as_upcoming(): void
    {
        $m = $this->meeting(['status' => 'مؤجل', 'starts_at' => now()->addDays(3)]);

        $this->assertSame('up', $m->liveState()[0]);
    }

    public function test_expired_upcoming_shows_not_held_without_scheduler(): void
    {
        // when_kind الاجتماعات: «قادم» مخزّنة واجتماع فات موعده أمس — يُعرض «لم ينعقد» فوراً
        $m = $this->meeting(['starts_at' => now()->subDay()]);

        $this->assertSame(['past', 'لم ينعقد', 'b-grey'], $m->liveState());
        $this->assertFalse($m->toCard()['up']);
        $this->assertFalse($m->canJoin()); // كان الزر مفتوحاً للأبد (شرط من جهة واحدة)
    }

    public function test_expired_upcoming_with_join_time_shows_ended(): void
    {
        $m = $this->meeting(['starts_at' => now()->subDay(), 'join_time' => now()->subDay()]);

        $this->assertSame(['past', 'منتهٍ', 'b-green'], $m->liveState());
    }

    public function test_running_meeting_within_cap_is_live(): void
    {
        $m = $this->meeting(['status' => 'جارٍ', 'starts_at' => now()->subMinutes(30)]);

        $this->assertSame(['up', 'جارٍ', 'b-amber'], $m->liveState());
        $this->assertTrue($m->canJoin());
    }

    public function test_running_meeting_past_cap_shows_ended(): void
    {
        // «جارٍ» عالق (لم يُنهِه أحد) — بعد المدة + 120د يُعرض منتهياً لا جارياً أبدياً
        $m = $this->meeting(['status' => 'جارٍ', 'starts_at' => now()->subHours(4)]);

        $this->assertSame(['past', 'منتهٍ', 'b-green'], $m->liveState());
        $this->assertFalse($m->canJoin());
    }

    public function test_can_join_window_is_closed_on_both_ends(): void
    {
        $inWindow = $this->meeting(['starts_at' => now()->addMinutes(3)]);
        $this->assertTrue($inWindow->canJoin());

        $justAfter = $this->meeting(['starts_at' => now()->subMinutes(30)]);
        // بدأ قبل 30د (المدة 60 + هامش 30) — ما زال داخل النافذة لكن حالته «قادم» فائتة؟
        // لا: 30د < 60د فالاجتماع لم يفت بعد (isPast يعتمد البداية+المدة) — الدخول متاح
        $this->assertTrue($justAfter->canJoin());

        $wayAfter = $this->meeting(['starts_at' => now()->subHours(2)]);
        $this->assertFalse($wayAfter->canJoin());
    }

    public function test_final_states_untouched(): void
    {
        $this->assertSame(['past', 'منتهٍ', 'b-green'], $this->meeting(['status' => 'منتهٍ'])->liveState());
        $this->assertSame(['past', 'ملغى', 'b-red'], $this->meeting(['status' => 'ملغى'])->liveState());
        $this->assertSame(['past', 'لم ينعقد', 'b-grey'], $this->meeting(['status' => 'لم ينعقد'])->liveState());
    }

    public function test_unparseable_when_label_is_not_past(): void
    {
        // نص عربي حر غير قابل للتحليل وبلا starts_at — سلوك آمن: ليس ماضياً (كما كان)
        $m = $this->meeting(['when_label' => 'الاثنين ٢٩ يونيو · ١١:٣٠ ص', 'starts_at' => null]);

        $this->assertSame('up', $m->liveState()[0]);
        $this->assertTrue($m->canJoin()); // بلا موعد قابل للتحليل — يُحسم بالحالة (السلوك السابق)
    }
}
