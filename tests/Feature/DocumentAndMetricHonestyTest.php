<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Jobs\ClassifyConvertedCaseJob;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\ExecService;
use App\Support\Qr;
use App\Support\ReportPrint;
use App\Support\ServiceDocs;
use App\Support\SummaryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ثلاثة ادّعاءات صغيرة تُقرأ إثباتاً.
 *
 * **ج** — نقشٌ يشبه QR وهو زخرفيّ حتميّ لا يُمسح، كان تحته «امسح لتأكيد الحضور» في
 * بطاقة الموعد و«موثق ومعتمد برقم مرجعي» في التقرير المختوم، وبذرتُه رابط تحقّق
 * `…/verify?ref=…&approved=1` **ولا مسار تحقّق في المشروع**.
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

    /** التقرير المختوم لا يَعِد بتوثيقٍ إلكترونيّ ولا يحمل رابط تحقّق وهميّاً. */
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
        $this->assertStringNotContainsString('/verify?', $html, 'لا رابط تحقّق — لا مسار له في المشروع');
        $this->assertStringNotContainsString('approved=1', $doc['approval']['qrSeed']);
    }

    /** والنقش نفسه زخرفيّ حتميّ — لا يُرافَق بأمرٍ بمسحه. */
    public function test_the_decorative_mark_is_never_paired_with_a_scan_instruction(): void
    {
        $this->assertStringNotContainsString(
            'امسح لتأكيد الحضور',
            file_get_contents(app_path('Support/AppointmentCardPdf.php'))
        );

        // حتميّ: البذرة نفسها تُنتج النقش نفسه — فليس ترميزاً لبيانات متغيّرة
        $this->assertSame(Qr::svg('REF-1'), Qr::svg('REF-1'));
        $this->assertNotSame(Qr::svg('REF-1'), Qr::svg('REF-2'));
    }

    // ── د) المقياس يُسمّى بما يقيس ──

    /** «معدّل إغلاق الملفّات» لا «معدّل الإنجاز» — في كل ما يصل العميل أو النموذج. */
    public function test_the_closure_metric_is_never_labelled_a_success_record(): void
    {
        foreach ([
            'resources/js/components/SpecialistPicker.tsx',
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
     * كل رسالةٍ تعرض قائمة `ServiceDocs` العامّة تُرفقها بتنويه.
     *
     * ولا يُعدّ عدد استدعاءات `ServiceDocs::for` مقياساً: استدعاءٌ واحد قد يُبنى منه
     * `$chips` تُعرض في رسالتين، ورسالةٌ أخرى تعرض قائمةً **كتبها الموظّف بنفسه** —
     * وسمُ تلك بأنها «عامّة بحسب النوع» يكذب عكسياً. فالمقياس: كل رسالة عرضت
     * `$chips` **المبنيّة من `ServiceDocs`** رافقها `NOTE`.
     */
    public function test_a_generic_document_checklist_announces_itself(): void
    {
        $this->assertNotEmpty(ServiceDocs::NOTE);

        // الرسائل التي تعرض القائمة العامّة — حُصرت بقراءة المواضع، ولكلٍّ سطرُ تنويه
        $genericBlocks = [
            'app/Support/TicketTriage.php' => 3,
            'app/Http/Controllers/Employee/TicketController.php' => 1,
            'app/Http/Controllers/Lawyer/TicketController.php' => 1,
        ];

        foreach ($genericBlocks as $file => $expected) {
            $notes = substr_count(file_get_contents(base_path($file)), 'ServiceDocs::NOTE');

            $this->assertSame(
                $expected,
                $notes,
                "{$file}: تنويهات القائمة العامّة {$notes} والمتوقَّع {$expected}"
            );
        }
    }

    /** ولا يُوسم بالعموم ما كتبه موظّفٌ لهذا الملفّ بعينه. */
    public function test_a_staff_written_request_is_not_labelled_generic(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Employee/TicketController.php'));

        // الرسالة المبنيّة من `$data['docs']` — اختيار الموظّف — بلا تنويه عموم
        $pos = strpos($src, 'للتمكن من دراسة طلبكم وإكمال الإجراءات');
        $this->assertNotFalse($pos);

        $block = substr($src, $pos, 400);
        $this->assertStringNotContainsString('ServiceDocs::NOTE', $block);
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
