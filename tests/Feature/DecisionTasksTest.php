<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Task;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\DecisionTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * استخراج القرارات → مهام: عند تعذّر الذكاء الاصطناعي لا تُحقَن مهام وهمية.
 * كان الاحتياط يُرجع 3 قرارات ثابتة فتتحوّل إلى سجلّات Task حقيقية للمحامي (نصّ عشوائي).
 */
class DecisionTasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_extract_decisions_returns_empty_when_ai_unavailable(): void
    {
        // بيئة الاختبار بلا مفاتيح AI ⇒ run() يُرجع null ⇒ لا قرارات (بدل 3 وهمية)
        $this->assertSame([], app(LegalAiService::class)->extractDecisions('نصّ ملخّص لا يهمّ محتواه'));
    }

    public function test_no_boilerplate_tasks_created_when_ai_fails(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-2026-5001',
            'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id, 'day' => 'اليوم', 'time' => '11:00',
            'when_label' => 'اليوم', 'session' => 'منتهية', 'status' => 'منتهية',
            'summary' => 'ملخّص الجلسة بلا قرارات صريحة', // يُشتقّ منه القرارات عبر AI
        ]);

        $created = DecisionTasks::create($consult, app(LegalAiService::class));

        $this->assertSame(0, $created);
        $this->assertSame(0, Task::where('assigned_to', $lawyer->id)->count());
    }
}
