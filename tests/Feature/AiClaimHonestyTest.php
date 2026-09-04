<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Correspondence;
use App\Models\Execution;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\ExternalSystemService;
use App\Support\AppointmentCard;
use App\Support\CorrespondenceFlow;
use App\Support\CorrFlow;
use App\Support\ExecService;
use App\Support\ReportPrint;
use App\Support\SummaryReport;
use App\Support\TicketResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * لا نصّ يدّعي عملاً لم يقع — اعتماداً، أو دراسةً، أو ردَّ جهةٍ حكوميّة.
 *
 * هذه أعطالٌ في **النصّ** لا في النموذج: الشيفرة تعمل كما كُتبت، والعبارة المرافقة
 * تصف شيئاً آخر. وأخطرها ما يصل العميل موقَّعاً أو مختوماً، لأنه يبني عليه قراراً.
 */
class AiClaimHonestyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * الشيفرة بلا تعليقاتها — لأن الحارس يحرس **ما يُنفَّذ** لا ما يُوثَّق.
     *
     * وقد أسقط هذا الملفُّ نفسَه أوّل تشغيل: التعليقات التي تشرح العطل تقتبس نصّه
     * القديم، فيطابقها التأكيد ويسقط. وحارسٌ يمنع توثيقَ العطل الذي يحرس منه
     * حارسٌ فاسد — يدفع كاتبه إلى حذف الشرح لا إلى إصلاح الشيفرة.
     *
     * تُحذف كتل `/* … *\/` (وتشمل تعليقات JSX) والأسطر التي تبدأ بـ`//` أو `*`.
     */
    private function codeOnly(string $path): string
    {
        $src = (string) file_get_contents(base_path($path));
        $src = (string) preg_replace('#/\*.*?\*/#su', '', $src);
        // المحدّد `#` لا `/`: النمط يحوي `//` فينهي محدّدَه ويصير الباقي «مُعدِّلاً مجهولاً»
        $src = (string) preg_replace('#^\s*(?://|\*).*$#mu', '', $src);

        return $src;
    }

    // ── تقرير الملخّص: الختم يتبع الاعتماد ──

    /** @return array{0:Ticket, 1:TicketSummary} */
    private function ticketSummary(bool $approved): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-CLAIM-'.uniqid(),
            'type' => 'نزاع تجاري', 'subject' => 'مطالبة', 'status' => 'قيد الدراسة', 'tone' => 'b-blue',
        ]);
        $summary = TicketSummary::create([
            'ticket_id' => $ticket->id, 'case_summary' => 'ملخّص.', 'facts' => 'وقائع.',
            'key_points' => 'توصيات.', 'attachments_summary' => 'مرفقات.',
            'status' => $approved ? 'approved' : 'pending',
            'approved_at' => $approved ? now() : null,
            'ai_generated' => true,
        ]);

        return [$ticket, $summary];
    }

    /**
     * **الحارس الأثمن:** ملخّصٌ غير معتمد لا يُطبع باسم مستشارٍ «معتمد» ولا بتاريخ اعتماد.
     *
     * كان الصفّان يطبعان اسم المحامي و**تاريخ اليوم** بلا شرط (`date('Y-m-d H:i')`)،
     * و`printSummary` بلا حارس اعتماد — فتخرج وثيقة تقول في صدرها «قيد الدراسة»
     * وفي ذيلها «معتمدة وموقّعة من الإدارة العليا بتاريخ اليوم».
     */
    public function test_an_unapproved_summary_report_claims_no_approval(): void
    {
        [$ticket, $summary] = $this->ticketSummary(approved: false);

        $doc = SummaryReport::doc($ticket, $summary, 'العميل', 'أ. سارة القحطاني');

        // التأكيد على **كتلة الاعتماد** لا على الوثيقة كلّها: اسم المحامي يظهر
        // مشروعاً في «المستشار المسؤول» — وهو مسؤولٌ فعلاً وإن لم يعتمد بعد.
        $rows = collect($doc['approval']['rows'])->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);

        $this->assertSame('— لم يُعتمد بعد —', $rows['المستشار المعتمد'], 'لا يُسمّى معتمِدٌ لم يعتمد');
        $this->assertSame('— لم يُعتمد بعد —', $rows['تاريخ الاعتماد'], 'ولا تاريخُ اليوم بديلاً');
        $this->assertSame('بيانات إصدار الوثيقة', $doc['approval']['title'], 'ولا ذيلُ توقيعٍ لا توقيع فيه');
        $this->assertStringNotContainsString('اعتماد وتوقيع الإدارة العليا', ReportPrint::html($doc));
    }

    /** وبعد الاعتماد يظهر المعتمِد وتاريخه — الحجب مؤقّتٌ لا دائم. */
    public function test_an_approved_summary_report_names_its_approver(): void
    {
        [$ticket, $summary] = $this->ticketSummary(approved: true);

        $doc = SummaryReport::doc($ticket, $summary, 'العميل', 'أ. سارة القحطاني');
        $rows = collect($doc['approval']['rows'])->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);

        $this->assertSame('أ. سارة القحطاني', $rows['المستشار المعتمد']);
        $this->assertSame($summary->approved_at->format('Y-m-d H:i'), $rows['تاريخ الاعتماد']);
        $this->assertStringContainsString('اعتماد وتوقيع الإدارة العليا', ReportPrint::html($doc));
    }

    // ── الرأي القانوني: الغياب يُعلَن ولا يُملأ ──

    /** «تمت الدراسة المبدئية للملف» لا تُكتب مكان رأيٍ خالٍ. */
    public function test_an_empty_opinion_is_declared_not_filled(): void
    {
        $src = $this->codeOnly('app/Http/Controllers/Lawyer/TicketController.php');

        $this->assertStringNotContainsString("'تمت الدراسة المبدئية للملف.'", $src);
        $this->assertStringContainsString('TicketResult::NO_RECOMMENDATIONS', $src);
        $this->assertNotEmpty(TicketResult::NO_RECOMMENDATIONS);
    }

    /** ورحلة التذكرة تصف موقع الملفّ لا دراسةً أطلقها زرُّ موظّف. */
    public function test_the_ticket_journey_claims_no_study_by_a_lawyer(): void
    {
        $src = $this->codeOnly('app/Support/TicketJourney.php');

        $this->assertStringNotContainsString('تمت الدراسة المبدئية من المستشار القانوني', $src);
        $this->assertStringContainsString('بلغ ملفّكم مرحلة الرأي القانوني', $src);
    }

    // ── طلب المستندات: الشرط على المصدر ──

    /**
     * قائمة النواقص لا تُنسب إلى «دراسة الطلب ومستنداته» إلّا بعد فحصٍ فعليّ.
     *
     * الاحتياطيّ يملأ `ai_missing` أيضاً بقالبٍ حتميّ لا يقرأ مستنداً واحداً — فكان
     * الشرط على امتلاء القائمة يُنتج نصّاً يقول إن مستنداتٍ دُرست ولم يُقرأ منها شيء.
     */
    public function test_the_document_request_wording_follows_the_source_not_the_list(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $fallback = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-FB-'.uniqid(), 'subject' => 'تنفيذ',
            'sanad' => 'حكم قضائي', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 1,
            'ai_source' => AiSource::Fallback->value,
            'ai_missing' => ['بيانات المنفَّذ ضده'],
        ]);
        ExecService::requestDocs($fallback);
        $msg = $fallback->messages()->where('role', 'نواقص')->latest('id')->first()?->body ?? '';

        $this->assertStringNotContainsString('بعد دراسة الطلب ومستنداته', $msg, 'القالب لا يقرأ مستنداً');
        $this->assertStringContainsString('قائمة عامّة', $msg);

        $real = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-AI-'.uniqid(), 'subject' => 'تنفيذ',
            'sanad' => 'حكم قضائي', 'status' => 'قيد الدراسة', 'tone' => 'b-blue', 'stage' => 1,
            'ai_source' => AiSource::AiSuccess->value,
            'ai_missing' => ['صك الحكم مكتملاً'],
        ]);
        ExecService::requestDocs($real);
        $msgReal = $real->messages()->where('role', 'نواقص')->latest('id')->first()?->body ?? '';

        $this->assertStringContainsString('بعد دراسة الطلب ومستنداته', $msgReal, 'والفحص الفعليّ يُقال');
    }

    // ── ردّ الجهة الحكوميّة ──

    /**
     * **العطل لا يُنتج موافقةً حكوميّة.**
     *
     * كان `reply()` يعيد «تفيدكم {الجهة} بالموافقة على الإجراء المطلوب» عند كل تعذّر،
     * فيُخزَّن في `reply_body` ويُطبع للعميل بعنوان «ردّ الجهة» في وثيقة رسميّة.
     */
    public function test_a_failed_lookup_never_fabricates_a_government_approval(): void
    {
        config([
            'services.external_corr.base_url' => 'https://ext.example.test',
            'services.external_corr.api_key' => 'k',
        ]);
        Http::fake(['ext.example.test/*' => Http::response([], 500)]);

        $client = User::factory()->create(['role' => Role::Client]);
        $corr = Correspondence::create([
            'user_id' => $client->id, 'number' => 'CORR-FAIL-'.uniqid(),
            'subject' => 'طلب إفراغ', 'entity' => 'محكمة التنفيذ', 'body' => 'نصّ.',
            'status' => 'مرسلة', 'stage' => 3, 'ext_ref' => 'EXT-9',
        ]);

        // العطل يعيد `null` ولا يُنتج نصّاً — نظير `send` تماماً
        $this->assertNull(app(ExternalSystemService::class)->reply($corr));

        // ولا تُسجَّل واقعة الاستلام على لا شيء
        $this->expectException(HttpException::class);
        CorrespondenceFlow::receive($corr->fresh(), 'المحامي');
    }

    /** وتعثّر الاستعلام لا يُقدّم مرحلة الملفّ — انقطاعُ الشبكة ليس تقدّماً. */
    public function test_a_failed_status_lookup_does_not_advance_the_stage(): void
    {
        config([
            'services.external_corr.base_url' => 'https://ext.example.test',
            'services.external_corr.api_key' => 'k',
        ]);
        Http::fake(['ext.example.test/*' => Http::response([], 500)]);

        $client = User::factory()->create(['role' => Role::Client]);
        $corr = Correspondence::create([
            'user_id' => $client->id, 'number' => 'CORR-ST-'.uniqid(),
            'subject' => 'طلب', 'entity' => 'محكمة التنفيذ', 'body' => 'نصّ.',
            'status' => 'مرسلة', 'stage' => 3, 'ext_ref' => 'EXT-9',
            'ext_status' => CorrFlow::EXT_STAGES[0],
        ]);

        $this->assertSame(
            CorrFlow::EXT_STAGES[0],
            app(ExternalSystemService::class)->status($corr),
            'الحالة تبقى كما هي حتى تُجيب الجهة'
        );
    }

    /** والمحاكاة تُعلن نفسها في النصّ — نظير وسم «محاكاة» في المرجع. */
    public function test_the_simulated_reply_announces_itself(): void
    {
        config(['services.external_corr.base_url' => '', 'services.external_corr.api_key' => '']);

        $client = User::factory()->create(['role' => Role::Client]);
        $corr = Correspondence::create([
            'user_id' => $client->id, 'number' => 'CORR-SIM-'.uniqid(),
            'subject' => 'طلب إفراغ', 'entity' => 'محكمة التنفيذ', 'body' => 'نصّ المخاطبة.',
            'status' => 'مرسلة', 'stage' => 3,
        ]);

        $reply = app(ExternalSystemService::class)->reply($corr);

        $this->assertNotNull($reply);
        $this->assertStringContainsString(ExternalSystemService::SIMULATED_PREFIX, (string) $reply);
        $this->assertStringNotContainsString('الموافقة', (string) $reply);
    }

    // ── بطاقة الموعد ──

    /** لا يُدَّعى إرسالٌ إلى بريدٍ لا وجود له. */
    public function test_the_appointment_card_claims_no_email_when_there_is_none(): void
    {
        // `users.email` عمودٌ NOT NULL — فالغياب يُمثَّل بسلسلةٍ خالية
        $noMail = User::factory()->create(['role' => Role::Client]);
        $noMail->forceFill(['email' => ''])->save();
        $withMail = User::factory()->create(['role' => Role::Client, 'email' => 'c@example.test']);

        $make = function (User $u) {
            $c = Consult::create([
                'user_id' => $u->id, 'ref' => 'CN-CARD-'.uniqid(), 'subject' => 'استشارة',
                'type' => 'استشارة', 'channel' => 'هاتفية', 'status' => 'موعد مؤكد',
                'tone' => 'b-green', 'lawyer' => 'أ. سارة',
            ]);

            return AppointmentCard::render($c->fresh(), ['label' => 'هاتفية', 'icon' => 'phone']);
        };

        $this->assertStringNotContainsString('أُرسل إشعار التأكيد', $make($noMail));
        $this->assertStringContainsString('لا بريد مسجَّل', $make($noMail));
        $this->assertStringContainsString('أُرسل إشعار التأكيد', $make($withMail));
    }

    // ── شارات ثابتة بلا عمود ──

    /** لا شارة تدّعي توثيقاً أو اعتماداً بلا عمودٍ يقرّره. */
    public function test_no_static_badge_claims_a_verification_that_never_happened(): void
    {
        foreach ([
            'resources/js/pages/dashboard.tsx' => 'عميل موثق',
            'resources/js/pages/myconsults.tsx' => 'جلسات معتمدة وموثقة',
            'resources/js/pages/tickets.tsx' => 'والردود القانونية المعتمدة',
        ] as $file => $claim) {
            $this->assertStringNotContainsString($claim, $this->codeOnly($file), $file);
        }

        $this->assertStringNotContainsString(
            "'مستشار معتمد'",
            file_get_contents(app_path('Http/Controllers/MeetingController.php')),
            'ولا يُوصف غيابُ الإسناد باعتماد'
        );
    }

    /** ولا توست يُعلن نجاح تحليلٍ قد يكون سقط إلى الاحتياطيّ. */
    public function test_no_toast_asserts_an_analysis_that_may_have_fallen_back(): void
    {
        foreach ([
            'resources/js/lib/consult-ui.tsx',
            'resources/js/pages/admin/consults.tsx',
            'resources/js/pages/admin/consultrecv.tsx',
        ] as $file) {
            $src = $this->codeOnly($file);

            $this->assertStringNotContainsString('اكتمل تحليل الفريق القانوني', $src, $file);
            $this->assertStringNotContainsString('تم تشغيل التحليل الذكي بنجاح', $src, $file);
            $this->assertStringNotContainsString('تم توليد وتوثيق ملخص الاستشارة', $src, $file);
        }
    }
}
