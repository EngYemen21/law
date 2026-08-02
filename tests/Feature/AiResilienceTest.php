<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\GenerateTicketSummaryJob;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مرونة الـAI الإنتاجية: قاطع الدائرة (يهدّئ المزوّد بعد نفاد الحصّة فلا يهدر النداءات)
 * + التصعيد البشري عند استنفاد إعادة المحاولات (لا شيء عالق بصمت).
 */
class AiResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_circuit_breaker_trips_on_quota_and_skips_provider(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429),
        ]);
        $svc = app(LegalAiService::class);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-7001', 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber',
        ]);

        // أوّل نداء يضرب Gemini ويأخذ 429 → يفتح قاطع الدائرة
        $svc->summarize($ticket);
        $firstCount = count(Http::recorded());

        $this->assertTrue(Cache::has('ai:cooldown:gemini'), 'المزوّد يجب أن يدخل التهدئة بعد نفاد الحصّة');
        $this->assertFalse($svc->available(), 'لا مزوّد متاح أثناء التهدئة');

        // النداء الثاني: المزوّد مهدّأ → يُتخطّى بلا أي طلب شبكي
        $svc->summarize($ticket);
        $this->assertSame($firstCount, count(Http::recorded()), 'يجب ألّا يُرسَل أي طلب جديد أثناء التهدئة');
    }

    public function test_glm_balance_error_trips_longer_cooldown(): void
    {
        config(['services.gemini.key' => '', 'services.glm.key' => 'k']);
        Cache::flush();
        Http::fake([
            '*/chat/completions' => Http::response(['error' => ['code' => '1113', 'message' => 'Insufficient balance']], 429),
        ]);
        $svc = app(LegalAiService::class);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-7002', 'type' => 'نزاع',
            'department' => 'القسم التجاري', 'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber',
        ]);

        $svc->summarize($ticket);
        $this->assertTrue(Cache::has('ai:cooldown:glm'));
        $this->assertFalse($svc->available());
    }

    public function test_escalates_to_lawyer_and_branch_employees_on_exhaustion(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $employee = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع الرياض']);
        $otherBranch = User::factory()->create(['role' => Role::Employee, 'branch' => 'فرع جدة']);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-7003', 'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري', 'branch' => 'فرع الرياض', 'assigned_lawyer_id' => $lawyer->id,
            'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber',
        ]);
        $ticket->summary()->create([
            'lawyer_id' => $lawyer->id, 'case_summary' => 'نائب', 'attachments_summary' => '',
            'facts' => '', 'key_points' => '', 'status' => 'awaiting_lawyer', 'ai_generated' => false,
        ]);

        // استنفاد نافذة إعادة المحاولة → تصعيد بشري
        (new GenerateTicketSummaryJob($ticket))->failed(new \Exception('retry window exhausted'));

        // (1) مهمّة حمراء + إشعار للمحامي المسند
        $this->assertDatabaseHas('tasks', ['assigned_to' => $lawyer->id, 'ref' => 'SB-2026-7003', 'tone' => 'b-red']);
        $this->assertSame(1, UserNotification::where('user_id', $lawyer->id)->count());
        // (2) إشعار لموظف الفرع نفسه — لا موظف فرع آخر
        $this->assertSame(1, UserNotification::where('user_id', $employee->id)->count());
        $this->assertSame(0, UserNotification::where('user_id', $otherBranch->id)->count());
    }

    public function test_no_escalation_if_summary_became_ai_generated(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'branch' => 'فرع الرياض']);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-7004', 'type' => 'نزاع',
            'department' => 'القسم التجاري', 'branch' => 'فرع الرياض', 'assigned_lawyer_id' => $lawyer->id,
            'status' => 'بانتظار اعتماد المستشار', 'tone' => 'b-amber',
        ]);
        $ticket->summary()->create([
            'lawyer_id' => $lawyer->id, 'case_summary' => 'تحليل حقيقي', 'attachments_summary' => 'x',
            'facts' => 'x', 'key_points' => 'x', 'status' => 'awaiting_lawyer', 'ai_generated' => true,
        ]);

        (new GenerateTicketSummaryJob($ticket))->failed(new \Exception('late'));

        // نجح التحليل متأخّراً → لا تصعيد
        $this->assertSame(0, Task::count());
        $this->assertSame(0, UserNotification::where('user_id', $lawyer->id)->count());
    }
}
