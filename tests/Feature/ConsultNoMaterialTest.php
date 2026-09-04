<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\FinalizeConsultJob;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\LegalAiService;
use App\Support\ConsultReport;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * جلسةٌ بلا تدوين لا يُكتب لها ملخّص — ولا يُنادى النموذج أصلاً.
 *
 * ما كان يُمرَّر إلى `consult.summary` خمسة حقول وصفيّة: القناة والمرجع والموضوع
 * واسم المحامي وملاحظات المستشار إن كُتبت. **بلا تفريغ الجلسة ولا تسجيلها.**
 * والتعليمة تأمر بكتابة «الوقائع ثم الرأي القانوني ثم الإجراءات».
 *
 * فلمّا خلت الملاحظات في `CN-2026-4622` (موضوع «عقود المقاولات» وحده) كتب النموذج:
 * «تمّ خلال الاستشارة عرض تفاصيل تعاقدية… تضمّنت بنود العقد، التزامات الأطراف،
 * جدول زمنيّ للتنفيذ، وآلية الدفع» — **محضر جلسة مختلَق بالكامل**، يقرؤه العميل
 * سجلّاً لاستشارته في «تقرير الاستشارة القانونية — نسخة العميل».
 *
 * ونظيرُه مُنِع في الاجتماعات منذ حادثة `M-26753`. وقياسُ `consult.summary` v2 على
 * مزوّدٍ حقيقيّ أثبت أن التعليمة وحدها لا تكفي: قلّ الاختلاق ولم ينقطع. فالمنع
 * **قبل النداء** لا داخله.
 */
class ConsultNoMaterialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '']);
        Cache::flush();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'ملخّص من واقع التدوين.']]]]],
        ], 200)]);
    }

    /** @return array{0:Consult, 1:User, 2:User} */
    private function endedConsult(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::all());

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-NM-'.uniqid(), 'subject' => 'عقود المقاولات',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'منتهية', 'session' => 'منتهية',
            'tone' => 'b-green', 'assigned_lawyer_id' => $lawyer->id, 'lawyer' => $lawyer->name,
        ]);

        return [$consult, $lawyer, $client];
    }

    /** **الحارس الأثمن:** بلا مادّة لا يغادر الخادمَ نداءٌ واحد. */
    public function test_a_consult_with_no_material_never_calls_the_model(): void
    {
        [$consult] = $this->endedConsult();

        (new FinalizeConsultJob($consult, ''))->handle(app(LegalAiService::class));

        Http::assertNothingSent();
        $this->assertNull($consult->fresh()->summary, 'ولا يُكتب نصّ — ولا حتى رسالة انتظار');
    }

    /** ولا قيد في السجلّ: قيدٌ بلا نداء يُفسد إحصاء الكلفة والتغطية. */
    public function test_no_run_is_logged_when_the_model_was_not_called(): void
    {
        [$consult] = $this->endedConsult();

        (new FinalizeConsultJob($consult, ''))->handle(app(LegalAiService::class));

        $this->assertSame(0, AiRun::where('task_type', 'consult.summary')->count());
    }

    /** بل يُنبَّه المحامي ليدوّن — والعميل لا يُشعَر بعطلٍ تشغيليّ لا يملك له فعلاً. */
    public function test_the_lawyer_is_prompted_and_the_client_is_not_alarmed(): void
    {
        [$consult, $lawyer, $client] = $this->endedConsult();

        (new FinalizeConsultJob($consult, ''))->handle(app(LegalAiService::class));

        $toLawyer = UserNotification::where('user_id', $lawyer->id)->pluck('body')->implode(' | ');
        $this->assertStringContainsString('بلا ملاحظات مدوَّنة', $toLawyer);

        // والعميل يُخبَر بختم جلسته — واقعةٌ تخصّه — دون أن يُحمّل عطلاً تشغيليّاً
        $toClient = UserNotification::where('user_id', $client->id)->pluck('body')->implode(' | ');
        $this->assertStringContainsString('انتهت جلسة استشارتك', $toClient);
        $this->assertStringNotContainsString('ملاحظات', $toClient, 'ولا يُقال له إن التدوين ناقص');

        // وأثرٌ في سجلّ التدقيق يشهد أن التوليد لم يقع ولماذا
        $this->assertStringContainsString(
            'لم يُولَّد',
            collect($consult->fresh()->audit ?? [])->pluck('after')->implode(' | ')
        );
    }

    /** ولا يتكرّر التنبيه حين يُختَم الطلب مرّتين (زرّ الإنهاء وويبهوك Zoom معاً). */
    public function test_the_prompt_is_not_repeated(): void
    {
        [$consult, $lawyer] = $this->endedConsult();

        foreach ([1, 2, 3] as $_) {
            (new FinalizeConsultJob($consult->fresh(), ''))->handle(app(LegalAiService::class));
        }

        $this->assertSame(1, UserNotification::where('user_id', $lawyer->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $consult->user_id)->count(), 'ولا إشعار العميل');
    }

    /** والتقرير يقول «بانتظار الإعداد» لا «بانتظار الاعتماد» — لا نصّ ينتظر توقيعاً. */
    public function test_the_report_says_awaiting_drafting_not_awaiting_approval(): void
    {
        [$consult, , $client] = $this->endedConsult();
        $consult->update(['priced_at' => now(), 'paid_at' => now()]);

        (new FinalizeConsultJob($consult->fresh(), ''))->handle(app(LegalAiService::class));

        $doc = ConsultReport::doc($consult->fresh(), $client->name);
        $section = collect($doc['blocks'])->first(fn ($b) => ($b['title'] ?? '') === '٤. ملخص الاستشارة');
        $next = collect($doc['blocks'])->first(fn ($b) => ($b['title'] ?? '') === '٦. الإجراء القادم');

        $this->assertSame(ConsultReport::AWAITING_DRAFT, $section['lines']);
        $this->assertSame(['بانتظار إعداد ملخص الاستشارة من المستشار'], $next['chips']);
    }

    /** وحين يدوّن المستشار متأخّراً يُولَّد الملخّص — المنع مشروطٌ لا دائم. */
    public function test_late_notes_produce_the_summary(): void
    {
        [$consult, $lawyer] = $this->endedConsult();

        (new FinalizeConsultJob($consult, ''))->handle(app(LegalAiService::class));
        $this->assertNull($consult->fresh()->summary);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/end", ['notes' => 'دوّن المستشار: نزاع على مستخلصات مقاولة غير مصروفة منذ أربعة أشهر.'])
            ->assertRedirect();

        $consult->refresh();
        $this->assertNotEmpty($consult->session_notes, 'التدوين المتأخّر يُحفظ ولا يُلقى لأن الجلسة مختومة');
        $this->assertNotEmpty($consult->summary, 'ويُولَّد الملخّص عليه');
        $this->assertSame(1, AiRun::where('task_type', 'consult.summary')->count());
    }

    /**
     * ومصدر الملخّص لا يدهس مصدر التحليل.
     *
     * كان `ai_source` عموداً واحداً لمسارين، فتعثّرُ الملخّص يُعيد وسم تحليلٍ سابق
     * **نجح**، والواجهة تقرأ `aiSource === 'fallback'` فتعنون «تعذّر التحليل الذكيّ».
     */
    public function test_the_summary_source_does_not_overwrite_the_analysis_source(): void
    {
        [$consult, $lawyer] = $this->endedConsult();
        $consult->update(['ai_source' => 'ai_success', 'ai_summary' => 'تحليل ما قبل الجلسة.']);

        $this->actingAs($lawyer)
            ->post("/lawyer/consults/{$consult->id}/end", ['notes' => 'دوّن المستشار: تفاصيل النزاع كما عرضها العميل.'])
            ->assertRedirect();

        $consult->refresh();
        $this->assertSame('ai_success', $consult->ai_source, 'مصدر التحليل ينجو');
        $this->assertNotNull($consult->summary_ai_source, 'ومصدر الملخّص في عموده');
    }
}
