<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Jobs\AnalyzeExecutionJob;
use App\Models\Execution;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LegalAiService;
use App\Support\ExecFlow;
use App\Support\ExecService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * **تعذّر الذكاء يُعاد جدولته ويُنبَّه به المكتب، ولا يُملأ بقالب** (قرار المالك 2026-09-12).
 *
 * كان الفرع الاحتياطيّ يكتب حكماً قانونيّاً — «سندٌ … مؤهّل للإحالة ومباشرة الإجراءات» —
 * بلا قراءة مستندٍ واحد، ويضيف ثلاثة إجراءات مثبّتة، فيُسعّر المحامي على تقديرٍ لم يقع.
 * وحين تموت المهمّة (مهلة أو 429) كان الملفّ يبقى «الدراسة قيد الإعداد» أبداً بلا علم أحد.
 */
class ExecStudyRetryTest extends TestCase
{
    use RefreshDatabase;

    private function exec(array $attrs = []): Execution
    {
        return Execution::create(array_merge([
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'EXE-R-'.uniqid(),
            'subject' => 'تنفيذ حكم مالي',
            'sanad' => 'حكم قضائي',
            'amount' => 90000,
            'defendant' => 'مؤسسة تجريبية',
            'stage' => 2,
            'status' => ExecFlow::label(2),
            'tone' => ExecFlow::tone(2),
        ], $attrs));
    }

    /** لا مفاتيح ذكاء في الاختبارات ⇒ المسار الاحتياطيّ — ويجب أن يعود فارغاً لا مُختلَقاً. */
    public function test_the_fallback_invents_nothing(): void
    {
        $result = app(LegalAiService::class)->analyzeExecution($this->exec());

        $this->assertSame(AiSource::Fallback->value, $result['source']);
        $this->assertSame('', $result['summary'], 'لا ملخّص يزعم فحصاً لم يقع');
        $this->assertSame([], $result['procedures'], 'لا إجراءات مثبّتة تُقرأ توصيةً');
        $this->assertSame('', $result['readiness']);
        $this->assertSame('', $result['difficulty']);
        $this->assertSame([], $result['recovery_indicators']);
        $this->assertSame(0, $result['expected_procedures_count']);
    }

    /** ونقصُ بياناتنا نحن يبقى — هو حقيقةٌ في السجلّ لا حكمٌ على مستند. */
    public function test_missing_own_data_is_still_reported(): void
    {
        $result = app(LegalAiService::class)->analyzeExecution($this->exec(['defendant' => '']));

        $this->assertContains('بيانات المنفَّذ ضده', $result['missing']);
    }

    /** التنبيه الداخليّ يصل الموظّف والمحامي المسنَد والإدارة — والعميل لا يرى أعطالنا. */
    public function test_a_failed_study_alerts_the_office_and_is_rescheduled(): void
    {
        Queue::fake();
        $employee = User::factory()->create(['role' => Role::Employee]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $exec = $this->exec(['ai_attempts' => 1, 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name]);

        ExecService::studyUnavailable($exec, 'cURL error 28');

        foreach ([$employee, $admin, $lawyer] as $staff) {
            $this->assertTrue(
                UserNotification::where('user_id', $staff->id)->where('body', 'like', '%تعذّرت دراسة%')->exists(),
                "لم يُنبَّه {$staff->role->value}",
            );
        }
        $this->assertFalse(UserNotification::where('user_id', $exec->user_id)->exists(), 'العميل لا يُبلَّغ بعطلٍ تشغيليّ');

        $note = $exec->messages()->reorder('id', 'desc')->first();
        $this->assertSame('note', $note?->who, 'ملاحظة داخليّة لا رسالة للعميل');
        $this->assertStringContainsString('تعذّرت الدراسة الذكيّة', (string) $note?->body);
        $this->assertStringContainsString('أُعيدت جدولة المحاولة', (string) $note?->body);
        $this->assertStringNotContainsString('مؤهّل للإحالة', (string) $note?->body, 'لا حكم مصطنع');

        Queue::assertPushed(AnalyzeExecutionJob::class);
    }

    public function test_the_retry_stops_at_the_cap_and_hands_the_file_to_a_human(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $exec = $this->exec(['ai_attempts' => AnalyzeExecutionJob::MAX_ATTEMPTS]);

        ExecService::studyUnavailable($exec, 'quota');

        $this->assertStringContainsString('استُنفدت المحاولات', (string) $exec->messages()->reorder('id', 'desc')->first()?->body);
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('body', 'like', '%يلزم فحص المستندات يدوياً%')->exists());
        Queue::assertNotPushed(AnalyzeExecutionJob::class, 'لا دوران بلا نهاية');
    }

    public function test_a_succeeded_or_closed_file_is_never_retried(): void
    {
        Queue::fake();

        ExecService::scheduleStudyRetry($this->exec(['ai_done' => true]));
        ExecService::scheduleStudyRetry($this->exec(['stage' => 9, 'status' => 'مغلق']));

        Queue::assertNotPushed(AnalyzeExecutionJob::class);
    }

    public function test_the_scheduled_sweep_picks_up_what_the_queue_dropped(): void
    {
        Queue::fake();
        // ماتت مهمّتها قبل أن تصل `failed()` — لا دراسة، ومحاولتها قديمة
        $stuck = $this->exec(['ai_attempts' => 1, 'ai_attempted_at' => now()->subHours(3)]);
        // حديثة العهد بمحاولة ⇒ تُترك لتهدئة المزوّد
        $fresh = $this->exec(['ai_attempts' => 1, 'ai_attempted_at' => now()->subMinutes(5)]);
        $done = $this->exec(['ai_done' => true]);
        $capped = $this->exec(['ai_attempts' => AnalyzeExecutionJob::MAX_ATTEMPTS, 'ai_attempted_at' => now()->subDay()]);

        $this->artisan('exec:retry-study')->assertSuccessful();

        Queue::assertPushed(AnalyzeExecutionJob::class, 1);
        Queue::assertPushed(AnalyzeExecutionJob::class, fn ($job) => $job->execution->is($stuck));
        foreach ([$fresh, $done, $capped] as $skipped) {
            Queue::assertNotPushed(AnalyzeExecutionJob::class, fn ($job) => $job->execution->is($skipped));
        }
    }
}
