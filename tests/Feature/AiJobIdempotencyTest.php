<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\AnalyzeExecutionJob;
use App\Jobs\TriageTicketOnOpenJob;
use App\Models\AiRun;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إعادة تشغيل وظيفة الطابور لا تُنتج المخرج مرّتين.
 *
 * الطابور يعيد تشغيل الوظيفة عند تعطّل العامل أو فشل عابر بعد تنفيذ أثرها وقبل
 * الإقرار بها. بلا حارس، تُنتج الإعادة: رسالة محادثة ثانية، وموجة إشعارات ثانية
 * لطاقم المكتب، وقيداً ثانياً في `ai_runs` يفسد كل قياس لاحق.
 *
 * وحارس التنفيذ كان قائماً بالمصادفة: الاحتياطيّ يضبط `ai_done = true` فيمنع
 * الإعادة. ولمّا صار الاحتياطيّ لا يدّعي اكتمالاً (P0) سقط الحارس معه — فالعقد
 * يُثبَّت هنا صراحةً بدل الاتّكال على أثر جانبيّ.
 */
class AiJobIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function execution(User $client): Execution
    {
        return Execution::create([
            'user_id' => $client->id, 'number' => 'EX-2026-'.uniqid(),
            'sanad' => 'شيك', 'subject' => 'تحصيل', 'amount' => 50000,
            'defendant' => 'مؤسسة الرمال', 'stage' => 1,
        ]);
    }

    public function test_rerunning_the_execution_job_does_not_duplicate_its_output(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $exec = $this->execution($client);

        // التشغيل الأوّل ثم إعادة تشغيل الوظيفة نفسها (نظير retry الطابور)
        (new AnalyzeExecutionJob($exec))->handle(app(LegalAiService::class));
        (new AnalyzeExecutionJob($exec))->handle(app(LegalAiService::class));

        $this->assertSame(1, AiRun::where('task_type', 'execution')->count(), 'قيد واحد لا قيدان');
        $this->assertSame(
            1,
            $exec->fresh()->messages()->where('who', 'note')->count(),
            'ملاحظة داخليّة واحدة — لا تكرار في محادثة الملفّ'
        );
        $this->assertSame(
            1,
            UserNotification::where('user_id', $employee->id)->count(),
            'موجة تنبيه واحدة للمكتب'
        );
    }

    public function test_rerunning_the_triage_job_does_not_duplicate_its_output(): void
    {
        config(['services.ai_agent.enabled' => true]);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-2026-'.uniqid(),
            'type' => 'نزاع تجاري', 'status' => 'جديدة', 'tone' => 'b-blue',
        ]);
        $details = 'نزاع تجاري مع مورّد بشأن توريد بضاعة.';

        (new TriageTicketOnOpenJob($ticket, $details, 'نزاع تجاري'))->handle(app(LegalAiService::class));
        $messagesAfterFirst = $ticket->fresh()->messages()->count();

        (new TriageTicketOnOpenJob($ticket, $details, 'نزاع تجاري'))->handle(app(LegalAiService::class));

        $this->assertSame(1, AiRun::where('task_type', 'triage')->count(), 'قيد فرز واحد');
        $this->assertSame(
            $messagesAfterFirst,
            $ticket->fresh()->messages()->count(),
            'لا رسالة ترحيب ثانية تصل العميل'
        );
    }

    /** الحارس يخصّ الكيان بعينه: طلبٌ آخر يُحلَّل عادةً. */
    public function test_the_guard_is_scoped_to_its_own_entity(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $first = $this->execution($client);
        $second = $this->execution($client);

        (new AnalyzeExecutionJob($first))->handle(app(LegalAiService::class));
        (new AnalyzeExecutionJob($second))->handle(app(LegalAiService::class));

        $this->assertSame(2, AiRun::where('task_type', 'execution')->count());
    }
}
