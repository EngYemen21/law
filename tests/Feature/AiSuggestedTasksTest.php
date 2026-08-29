<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\DecisionTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرارات الجلسة اقتراحاتٌ قبل أن تصير مهامّ — مطلب المرحلة P3.
 *
 * كان مسارا ملخّص Zoom يُنشئان مهامّ لدى المحامي **تلقائياً** من نصٍّ استخرجه
 * نموذج: أي أن مخرج نموذج يُنشئ التزاماً على إنسان بلا أن يقرّه أحد. والزرّ
 * البشريّ (`createTasks`) كان موجوداً أصلاً — فالمسار التلقائيّ كان يلتفّ حوله.
 */
class AiSuggestedTasksTest extends TestCase
{
    use RefreshDatabase;

    private function consultWithDecisions(User $lawyer): Consult
    {
        return Consult::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'ref' => 'CN-'.uniqid(), 'subject' => 'نزاع', 'channel' => 'مرئية',
            'status' => 'منتهية', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'decisions' => ['إعداد مذكّرة الردّ', 'طلب كشف حساب'],
        ]);
    }

    // ── الاقتراح لا يُنشئ التزاماً ──

    public function test_suggesting_stores_decisions_without_creating_any_task(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consultWithDecisions($lawyer);

        $count = DecisionTasks::suggest($consult, app(LegalAiService::class));

        $this->assertSame(2, $count);
        $this->assertSame(0, Task::count(), 'لا مهمّة تُنشأ بلا اعتماد بشريّ');
        $this->assertSame(['إعداد مذكّرة الردّ', 'طلب كشف حساب'], $consult->fresh()->suggested_tasks);
        $this->assertFalse((bool) $consult->fresh()->tasks_created);
    }

    /** ويبهوك ثانٍ أو إعادة تشغيل لا يُضاعفان الاقتراحات. */
    public function test_suggesting_twice_is_idempotent(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consultWithDecisions($lawyer);

        DecisionTasks::suggest($consult, app(LegalAiService::class));
        $second = DecisionTasks::suggest($consult->fresh(), app(LegalAiService::class));

        $this->assertSame(0, $second);
        $this->assertCount(2, $consult->fresh()->suggested_tasks);
    }

    // ── الاعتماد وحده يُنشئ المهامّ ──

    public function test_approval_turns_the_suggestions_into_real_tasks(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consultWithDecisions($lawyer);
        DecisionTasks::suggest($consult, app(LegalAiService::class));

        $created = DecisionTasks::create($consult->fresh(), app(LegalAiService::class), $lawyer);

        $this->assertSame(2, $created);
        $this->assertSame(2, Task::where('assigned_to', $lawyer->id)->count());
        $this->assertTrue((bool) $consult->fresh()->tasks_created);
    }

    public function test_approval_is_idempotent_after_tasks_exist(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consultWithDecisions($lawyer);
        DecisionTasks::suggest($consult, app(LegalAiService::class));

        DecisionTasks::create($consult->fresh(), app(LegalAiService::class), $lawyer);
        $again = DecisionTasks::create($consult->fresh(), app(LegalAiService::class), $lawyer);

        $this->assertSame(0, $again);
        $this->assertSame(2, Task::count());
    }

    // ── لا اختلاق ──

    public function test_no_decisions_means_no_suggestions(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = Consult::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'ref' => 'CN-'.uniqid(), 'subject' => 'نزاع', 'channel' => 'مرئية',
            'status' => 'منتهية', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->assertSame(0, DecisionTasks::suggest($consult, app(LegalAiService::class)));
        $this->assertEmpty($consult->fresh()->suggested_tasks ?? []);
        $this->assertSame(0, Task::count());
    }

    public function test_meetings_follow_the_same_contract(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $meeting = Meeting::create([
            'ref' => 'M-'.uniqid(), 'title' => 'اجتماع', 'when_label' => 'اليوم',
            'status' => 'منتهٍ', 'assigned_lawyer_id' => $lawyer->id,
            'decisions' => ['متابعة العقد'],
        ]);

        DecisionTasks::suggest($meeting, app(LegalAiService::class));

        $this->assertSame(['متابعة العقد'], $meeting->fresh()->suggested_tasks);
        $this->assertSame(0, Task::count());
    }

    /** مَن اعتمد سابقاً لا تتغيّر حالته: `tasks_created` يمنع اقتراحاً جديداً. */
    public function test_an_already_approved_record_is_not_re_suggested(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consultWithDecisions($lawyer);
        $consult->update(['tasks_created' => true]);

        $this->assertSame(0, DecisionTasks::suggest($consult->fresh(), app(LegalAiService::class)));
    }
}
