<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حدود تشغيلية: شاشات تُحمّل بيانات المكتب كلّه بلا قيد، فتكبر حمولتها
 * مع عمر المكتب حتى تتجاوز الذاكرة أو المهلة.
 */
class OperationalBoundsTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): User
    {
        return User::factory()->create(['role' => Role::Employee]);
    }

    /** التقويم غرضه ما هو محجوز قبل الجدولة — لا أرشيف المكتب منذ تأسيسه. */
    public function test_office_calendar_excludes_events_outside_the_window(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-B-1', 'type' => 'تجاري',
            'status' => 'منظورة', 'tone' => 'b-blue',
        ]);

        CaseHearing::create([
            'case_id' => $case->id, 'title' => 'جلسة قريبة', 'day' => 'غد', 'time' => '10:00',
            'starts_at' => now()->addDays(3), 'status' => 'قادمة', 'tone' => 'b-blue',
        ]);
        CaseHearing::create([
            'case_id' => $case->id, 'title' => 'جلسة قديمة', 'day' => 'قديم', 'time' => '10:00',
            'starts_at' => now()->subYears(2), 'status' => 'منتهية', 'tone' => 'b-slate',
        ]);
        Meeting::create([
            'ref' => 'M-B-1', 'title' => 'اجتماع بعيد', 'when_label' => 'لاحقاً',
            'status' => 'قادم', 'starts_at' => now()->addYear(),
        ]);

        $this->actingAs($this->employee())
            ->get(route('employee.calendar'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $titles = collect($page->toArray()['props']['events'])->pluck('title')->implode(' | ');

                $this->assertStringContainsString('جلسة قريبة', $titles);
                $this->assertStringNotContainsString('جلسة قديمة', $titles);
                $this->assertStringNotContainsString('اجتماع بعيد', $titles);
            });
    }

    /** حدث بلا موعد محدّد يبقى ظاهراً: استبعاده يُخفي ما لم يُجدول بعد. */
    public function test_office_calendar_keeps_events_without_a_timestamp(): void
    {
        Meeting::create([
            'ref' => 'M-B-2', 'title' => 'اجتماع بلا موعد', 'when_label' => 'يُحدَّد لاحقاً',
            'status' => 'قادم', 'starts_at' => null,
        ]);

        $this->actingAs($this->employee())
            ->get(route('employee.calendar'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $titles = collect($page->toArray()['props']['events'])->pluck('title')->implode(' | ');
                $this->assertStringContainsString('اجتماع بلا موعد', $titles);
            });
    }
}
