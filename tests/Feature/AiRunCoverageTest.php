<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\FinalizeConsultJob;
use App\Jobs\GenerateMeetingSummaryJob;
use App\Jobs\GenerateTicketSummaryJob;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\Ai\AiPromptRegistry;
use App\Services\Ai\AiReviewInbox;
use App\Services\LegalAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تغطية سجلّ القرارات: **كل** مخرج له قيد.
 *
 * كانت ستّة مخرجات تُنتَج بلا أثرٍ واحد — خمسة منها مصنّفة `high` في
 * `AiPolicyGate::SENSITIVITY`: `ticket.summary` و`consult.summary` و`meeting.summary`
 * و`meeting.decisions` و`assistant.draft`. فلا كلفتها محسوبة، ولا نموذجها معروف،
 * ولا تصل صندوق المراجعة الذي تفرضه الخطة أصلاً. أي أن «عالي الحساسيّة» كان وصفاً
 * في خريطة لا سلوكاً في النظام.
 */
class AiRunCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * معرّفات مسجَّلة بلا قيد **بحقّ** — ولكلٍّ سببٌ معلَن.
     *
     * `lawyer.match`: دالّتاه (`chooseLawyer` و`rankLawyers`) محالتان للتقاعد ولا
     * تُناديان من أيّ مسار إنتاج — الإسناد حتميّ في `TicketAssignment`. فلا مخرج
     * يُقيَّد أصلاً. ويحرس هذا الاستثناءَ الاختبارُ التالي: رفع التقاعد بلا إضافة قيد
     * يُسقطه.
     */
    private const NOT_LOGGED = ['lawyer.match'];

    private function fakeProvider(string $text): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $text]]]]],
        ], 200)]);
    }

    /**
     * **الحارس الأثمن**: كل معرّف مسجَّل يقابله قيدٌ في طبقة الأعمال.
     *
     * فحصٌ مصدريّ نظير `AiPromptRegistryTest::test_every_registered_prompt_id_reaches_a_real_call`
     * — إثباته حيّاً يلزمه تشغيل كل مسار بمزوّد، وهذا يمنع عودة العطل عند إضافة
     * معرّف جديد: من يُسجّل تعليمةً ولا يُقيّد مخرجها يُسقط هذا الاختبار.
     */
    public function test_every_registered_prompt_id_has_a_recorded_run(): void
    {
        $sources = collect(glob(app_path('**/*.php')))
            ->merge(glob(app_path('**/**/*.php')))
            ->merge(glob(app_path('**/**/**/*.php')))
            ->unique()
            ->map(fn ($f) => file_get_contents($f))
            ->implode("\n");

        $missing = [];
        foreach (array_keys(AiPromptRegistry::PROMPTS) as $id) {
            if (in_array($id, self::NOT_LOGGED, true)) {
                continue;
            }
            // اسم القيد قد يخالف معرّف التعليمة: `execution.analyze` يُقيَّد `execution`،
            // و`ticket.triage` يُقيَّد `triage`. فيُقبل المعرّف كاملاً أو أيّ من مقطعيه —
            // المهمّ ألّا يمرّ مخرجٌ بلا تسجيل.
            $parts = explode('.', $id);
            $names = array_unique([$id, $parts[0], end($parts)]);
            $found = false;
            foreach ($names as $name) {
                if (str_contains($sources, "AiRunLogger::log('{$name}'")) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $missing[] = $id;
            }
        }

        $this->assertSame([], $missing, 'معرّفات مسجَّلة بلا قيد في سجلّ القرارات: '.implode('، ', $missing));
    }

    /**
     * الاستثناء يبقى صادقاً: `lawyer.match` معفىً لأن دالّتيه متقاعدتان.
     * فإن أُعيدتا إلى الخدمة بلا قيد، سقط هذا الاختبار وأُعيد فتح الملفّ.
     */
    public function test_the_retired_lawyer_match_exemption_stays_true(): void
    {
        $service = file_get_contents(app_path('Services/LegalAiService.php'));
        $assignment = file_get_contents(app_path('Support/TicketAssignment.php'));

        $this->assertSame(2, substr_count($service, 'غير مستعملة حالياً'), 'الدالّتان ما زالتا موسومتين بالتقاعد');
        $this->assertStringContainsString('أُحيل للتقاعد', $assignment, 'ونداؤهما ما زال معطَّلاً');
    }

    /** ملخّص الملفّ يُقيَّد بنموذجه وإصداره وأثره. */
    public function test_a_ticket_summary_is_logged_with_its_model_and_cost(): void
    {
        $this->fakeProvider(json_encode([
            'case_summary' => 'مطالبة بقيمة عقد توريد.',
            'attachments_summary' => 'عقد التوريد.',
            'facts' => ['لم تُسلَّم البضاعة.'],
            'key_points' => ['الأساس النظاميّ للفسخ.'],
        ], JSON_UNESCAPED_UNICODE));

        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-COV-'.uniqid(), 'type' => 'نزاع تجاري',
            'status' => 'قيد التحليل', 'tone' => 'b-blue',
        ]);
        TicketSummary::create([
            'ticket_id' => $ticket->id, 'case_summary' => '', 'attachments_summary' => '',
            'facts' => '', 'key_points' => '', 'status' => 'awaiting_lawyer',
            'result_status' => 'none', 'ai_generated' => false,
        ]);

        (new GenerateTicketSummaryJob($ticket))->handle(app(LegalAiService::class));

        $run = AiRun::where('task_type', 'ticket.summary')->latest('id')->first();

        $this->assertNotNull($run, 'مخرجٌ عالي الحساسيّة لا يجوز أن يُنتَج بلا قيد');
        $this->assertSame($ticket->number, $run->entity_ref);
        $this->assertNotNull($run->prompt_version, 'الإصدار يُخزَّن وإلّا كذب السجلّ');
        $this->assertNotNull($run->trace_id);
        $this->assertSame(AiRun::STATUS_NEEDS_REVIEW, $run->status, 'حساسيّتها high');
    }

    /** وملخّص الاستشارة يبلغ صندوق محاميها هو. */
    public function test_a_consult_summary_reaches_the_review_inbox_of_its_lawyer(): void
    {
        $this->fakeProvider('ملخّص الاستشارة: الوقائع ثم الرأي ثم الإجراءات.');

        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $other = User::factory()->create(['role' => Role::Lawyer]);

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-COV-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'video', 'status' => 'منتهية',
            'tone' => 'b-green', 'assigned_lawyer_id' => $lawyer->id, 'lawyer' => $lawyer->name,
        ]);

        (new FinalizeConsultJob($consult, 'دوّن المستشار: نزاع على مستخلصات مقاولة غير مصروفة.'))->handle(app(LegalAiService::class));

        $run = AiRun::where('task_type', 'consult.summary')->latest('id')->first();
        $this->assertNotNull($run);

        $this->assertContains(
            $consult->ref,
            AiReviewInbox::forUser($lawyer)->pluck('entity_ref')->all(),
            'تبلغ صندوق محاميها'
        );
        $this->assertNotContains(
            $consult->ref,
            AiReviewInbox::forUser($other)->pluck('entity_ref')->all(),
            'ولا تبلغ زميله'
        );
    }

    /**
     * واجتماعٌ بلا مادّة لا يُقيَّد — لم يُنادَ النموذج أصلاً.
     * قيدٌ بلا نداء يُفسد إحصاء الكلفة ويُظهر في الصندوق مخرجاً لم يُنتَج.
     */
    public function test_a_meeting_with_no_material_records_no_run(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake();

        $meeting = Meeting::create([
            'title' => 'اجتماع بلا مادّة', 'type' => 'داخلي',
            'date' => now()->toDateString(), 'time' => '10:00',
            'when_label' => 'اليوم 10:00', 'status' => 'منتهي', 'tone' => 'b-grey',
        ]);

        (new GenerateMeetingSummaryJob($meeting, ''))->handle(app(LegalAiService::class));

        $this->assertSame(0, AiRun::where('task_type', 'meeting.summary')->count());
        Http::assertNothingSent();
    }
}
