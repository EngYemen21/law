<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * التبويب الزمني يعرض الحالة **الحيّة** لا المخزَّنة.
 *
 * أبلغ مستخدم عن تناقض حقيقي: اجتماع M-26885 يُعرض «قادم» بينما محاولة الدخول تردّ
 * «انتهت جلسة هذا الاجتماع». السبب أن TimelineCard كان يقرأ العمود المخزَّن، بينما حارس
 * الدخول (MeetingController) يقرأ liveState().
 *
 * والحالة المخزَّنة **تتأخّر عمداً**: AutoCloseMissedMeetings يمهل 12 ساعة قبل الحسم، فبين
 * انقضاء الموعد والحسم توجد فجوة يجب أن يسدّها الاشتقاق الحيّ — وهذا ما توثّقه liveState
 * نفسها: «يُعرض لم ينعقد فوراً دون انتظار المجدول».
 */
class TimelineLiveStatusTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    /** اجتماع فات موعده بساعات قليلة: مخزَّن «قادم» ويجب أن يُعرض «لم ينعقد». */
    public function test_a_past_meeting_is_not_shown_as_upcoming(): void
    {
        $client = $this->client();
        Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-STALE', 'title' => 'تجهيز جلسة',
            'when_label' => 'أمس', 'starts_at' => now()->subHours(7),
            'status' => 'قادم', 'dur' => '60 دقيقة', // المخزَّنة بائتة عمداً (داخل مهلة الـ12 ساعة)
        ]);

        $this->actingAs($client)->get(route('calendar'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('events.0.status', 'لم ينعقد')
                ->where('events.0.when', 'past'));
    }

    /** واجتماع قادم فعلاً يبقى «قادم» — حارس ضدّ حسم مفرط. */
    public function test_a_genuinely_upcoming_meeting_stays_upcoming(): void
    {
        $client = $this->client();
        Meeting::create([
            'user_id' => $client->id, 'ref' => 'M-FUTURE', 'title' => 'اجتماع قادم',
            'when_label' => 'غداً', 'starts_at' => now()->addDay(),
            'status' => 'قادم', 'dur' => '60 دقيقة',
        ]);

        $this->actingAs($client)->get(route('calendar'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('events.0.status', 'قادم')
                ->where('events.0.when', 'up'));
    }

    /** جلسة «مجدولة» فات موعدها تُعرض «فائتة — بانتظار النتيجة» (فحص كان يُسقَط سهواً). */
    public function test_a_lapsed_hearing_is_marked_pending_result(): void
    {
        $client = $this->client();
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-LAPSE-1', 'type' => 'نزاع',
            'status' => 'نشطة', 'tone' => 'b-blue',
        ]);
        CaseHearing::create([
            'case_id' => $case->id, 'title' => 'الجلسة الأولى', 'day' => now()->subDay()->format('Y-m-d'),
            'time' => '10:00', 'starts_at' => now()->subDay(), 'court' => 'المحكمة التجارية',
            'status' => 'مجدولة',
        ]);

        $this->actingAs($client)->get(route('calendar', ['kind' => 'hearing']))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('events.0.status', 'فائتة — بانتظار النتيجة'));
    }
}
