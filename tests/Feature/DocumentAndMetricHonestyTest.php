<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Jobs\ClassifyConvertedCaseJob;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\DocumentVerification;
use App\Support\ExecService;
use App\Support\Qr;
use App\Support\ReportPrint;
use App\Support\SummaryReport;
use App\Support\TicketDocumentRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ثلاثة ادّعاءات صغيرة تُقرأ إثباتاً.
 *
 * **ج** — كان نقشٌ يشبه QR زخرفيّاً حتميّاً لا يُمسح، تحته «امسح لتأكيد الحضور» في
 * بطاقة الموعد و«موثق ومعتمد برقم مرجعي» في التقرير المختوم، وبذرتُه رابط تحقّق
 * `…/verify?ref=…&approved=1` **ولا مسار تحقّق في المشروع**. صار الرمز حقيقيّاً يحيل إلى
 * صفحة تحقّقٍ موقَّعة (`DocumentVerification`؛ حارسه الكامل `QrCodeTest`)، ويبقى هنا
 * حارسُ الادّعاءين: لا اعتمادَ في الرابط، ولا أمرَ مسحٍ بما لا يقع.
 *
 * **د** — «معدّل الإنجاز» المعروض للعميل وهو يختار محاميه: يَعُدّ الملفّات المغلقة،
 * و«صدر الحكم» حالةُ إغلاق تُحتسب مهما كان اتّجاه الحكم — فهو مقياس تشغيليّ يُقدَّم
 * سجلَّ كفاءة قضائيّة.
 *
 * **هـ** — قوائم مستندات عامّة بالنوع تُعرض بالصياغة نفسها التي تُعرض بها حصيلةُ فحصٍ
 * لملفّ العميل، فلا يُفرَّق بين «نقصك كذا بعد الاطّلاع» و«هذا ما يلزم عادةً».
 */
class DocumentAndMetricHonestyTest extends TestCase
{
    use RefreshDatabase;

    // ── ج) لا ادّعاء تحقّق فوق نقشٍ زخرفيّ ──

    /** التقرير المختوم لا يحمل رابط تحقّقٍ يُعلن الاعتماد بنفسه — رابطه موقَّعٌ إلى صفحةٍ قائمة. */
    public function test_the_sealed_report_claims_no_electronic_verification(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-QR-'.uniqid(), 'type' => 'نزاع تجاري',
            'subject' => 'مطالبة', 'status' => 'مكتملة', 'tone' => 'b-green',
        ]);
        $summary = TicketSummary::create([
            'ticket_id' => $ticket->id, 'case_summary' => 'ملخّص.', 'facts' => 'وقائع.',
            'key_points' => 'توصيات.', 'attachments_summary' => 'مرفقات.',
            'status' => 'approved', 'result_status' => 'approved', 'ai_generated' => true,
        ]);

        $doc = SummaryReport::doc($ticket, $summary, 'العميل', 'المستشار');
        $html = ReportPrint::html($doc);

        $this->assertStringNotContainsString('موثق ومعتمد برقم مرجعي', $html);
        $this->assertStringNotContainsString('/verify?', $html, 'لا رابط تحقّق بالاستعلام القديم');
        $this->assertStringNotContainsString('approved=1', $doc['approval']['qr']);
        $this->assertSame(DocumentVerification::url(DocumentVerification::SUMMARY, $ticket->number), $doc['approval']['qr']);
    }

    /** الرمز لا يُرافَق بأمرٍ بمسحٍ لا يقع — المسح يتحقّق من البطاقة، لا يسجّل حضوراً. */
    public function test_the_code_is_never_paired_with_a_false_scan_instruction(): void
    {
        foreach ([app_path('Support/AppointmentCardPdf.php'), resource_path('js/pages/appointments.tsx')] as $file) {
            $this->assertStringNotContainsString('امسح لتأكيد الحضور', (string) file_get_contents($file));
        }

        // رمزٌ يُرمِّز نصّه: النصّ نفسه ⇒ الرمز نفسه، ونصّان ⇒ رمزان
        $this->assertSame(Qr::svg('REF-1'), Qr::svg('REF-1'));
        $this->assertNotSame(Qr::matrix('REF-1'), Qr::matrix('REF-2'));
    }

    // ── د) المقياس يُسمّى بما يقيس ──

    /** «معدّل إغلاق الملفّات» لا «معدّل الإنجاز» — في كل ما يصل العميل أو النموذج. */
    public function test_the_closure_metric_is_never_labelled_a_success_record(): void
    {
        // (`SpecialistPicker.tsx` كان هنا — حُذف مُنتقي العميل الميّت 2026-09-26)
        foreach ([
            'app/Services/LegalAiService.php',
        ] as $file) {
            $src = file_get_contents(base_path($file));
            $this->assertStringNotContainsString('معدّل الإنجاز', $src, $file);
        }

        // وتعليمة الترتيب لا تطلب «سجلّ نجاح» من النموذج
        $this->assertStringNotContainsString(
            'سجلّ نجاح',
            file_get_contents(app_path('Services/LegalAiService.php'))
        );
    }

    // ── هـ) القائمة العامّة تُعرّف نفسها ──

    /**
     * كل رسالةٍ تعرض قائمة القسم تُرفقها بتنويه.
     *
     * كانت القائمة (`ServiceDocs`، حُذف 2026-09-26) تُبنى في خمسة مواضع ولكلٍّ سطرُ تنويهه، فعُدّت
     * التنويهات مصدريّاً. صار الغلاف واحداً (`TicketDocumentRequirements::requestHtml`) والتنويه فيه —
     * فيُثبت هنا سلوكاً: قائمةٌ معروضة ⇒ تنويهها معها. وحارس الغلاف الواحد في `TicketDocumentRequirementsTest`.
     */
    public function test_a_generic_document_checklist_announces_itself(): void
    {
        $this->assertNotEmpty(TicketDocumentRequirements::NOTE);

        $ticket = Ticket::create([
            'number' => 'TK-NOTE-1', 'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'type' => 'خدمةٌ بلا قسم', 'status' => 'جديدة', 'tone' => 'b-blue',
        ]);

        $html = TicketDocumentRequirements::requestHtml($ticket, 'نأمل إرفاق المستندات التالية:');

        $this->assertStringContainsString('doc-chip', $html);
        $this->assertStringContainsString(e(TicketDocumentRequirements::NOTE), $html);
    }

    /** ولا يُوسم بالعموم ما كتبه موظّفٌ لهذا الملفّ بعينه. */
    public function test_a_staff_written_request_is_not_labelled_generic(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Employee/TicketController.php'));

        // الرسالة المبنيّة من `$data['docs']` — اختيار الموظّف — بلا تنويه عموم
        $pos = strpos($src, 'للتمكن من دراسة طلبكم وإكمال الإجراءات');
        $this->assertNotFalse($pos);

        $block = substr($src, $pos, 400);
        $this->assertStringNotContainsString('TicketDocumentRequirements::NOTE', $block);
        $this->assertStringNotContainsString('requestHtml', $block);
    }

    /** وطلب مستندات التنفيذ يُفرّق بين نواقص التحليل وقائمة الاستقبال. */
    public function test_execution_document_request_distinguishes_analysis_from_intake(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $intake = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-INT-'.uniqid(), 'subject' => 'تنفيذ',
            'sanad' => 'حكم قضائي', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2,
        ]);
        ExecService::requestDocs($intake);
        $intakeMsg = $intake->messages()->where('role', 'نواقص')->latest('id')->first()?->body ?? '';
        $this->assertStringContainsString('قائمة عامّة', $intakeMsg);

        $analysed = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-AN-'.uniqid(), 'subject' => 'تنفيذ',
            'sanad' => 'حكم قضائي', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 2,
            // المصدر جزءٌ من الحالة لا زينة: صياغة «بعد دراسة الطلب ومستنداته»
            // صارت تتبع `ai_source` لا امتلاء `ai_missing` — لأنّ الاحتياطيّ يملأ القائمة
            // أيضاً بقالبٍ لا يقرأ مستنداً. فتحليلٌ فعليّ يلزمه مصدرٌ فعليّ.
            'ai_source' => AiSource::AiSuccess->value,
            'ai_missing' => ['صك الحكم مكتملاً', 'ما يثبت اكتساب القطعية'],
        ]);
        ExecService::requestDocs($analysed);
        $analysedMsg = $analysed->messages()->where('role', 'نواقص')->latest('id')->first()?->body ?? '';
        $this->assertStringContainsString('بعد دراسة الطلب ومستنداته', $analysedMsg);
        $this->assertStringNotContainsString('قائمة عامّة', $analysedMsg);
    }

    // ── و) لا يُنسب إلى الفريق ما لم يكتبه، ولا يُسمّى «تحليلاً» نسخٌ حرفيّ ──

    /**
     * إشعار الإحالة لا يقول إن الفريق «جهّز» ملخّصاً كتبه قالب.
     *
     * `TicketTriage` تحفظ `fallbackSummary` بـ`ai_generated = false` ثم تُرسل الرسالة
     * فوراً — والتلخيص الحقيقيّ في الطابور بعدُ. فالقول «جهّز الفريق القانوني ملخص
     * الملف» يُخبر العميل بعملٍ لم يقع، ويَنسب إلى بشرٍ ما كتبه قالب.
     */
    public function test_the_referral_notice_does_not_credit_a_team_with_template_work(): void
    {
        $src = file_get_contents(app_path('Support/TicketTriage.php'));

        $this->assertStringNotContainsString('وجهّز الفريق القانوني ملخص الملف', $src);
        $this->assertStringContainsString('قيد الإعداد بانتظار اعتماد المستشار', $src);
    }

    /** وعنوان رسالة القضية يتبع مصدرها: «تحليل ذكي» للمنقَّح، و«بيانات الطلب» للنسخ. */
    public function test_the_case_analysis_title_follows_its_source(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-CLS-'.uniqid(), 'type' => 'نزاع تجاري',
            'department' => 'القضايا التجارية', 'subject' => 'مطالبة',
            'status' => 'مكتملة', 'tone' => 'b-green',
        ]);
        $analysis = ['type' => 'نزاع تجاري', 'department' => 'القضايا التجارية'];

        // عند الإنشاء: نسخٌ من التذكرة بلا نداء نموذج
        $atCreation = ClassifyConvertedCaseJob::analysisBody($ticket, $analysis);
        $this->assertStringContainsString('بيانات الطلب', $atCreation);
        $this->assertStringNotContainsString('تحليل ذكي', $atCreation);

        // وبعد تنقيح النموذج فعلاً
        $refined = ClassifyConvertedCaseJob::analysisBody($ticket, $analysis, refined: true);
        $this->assertStringContainsString('تحليل ذكي للطلب', $refined);
    }
}
