<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Http\Middleware\HandleInertiaRequests;
use App\Mail\VerificationCodeMail;
use App\Models\Consult;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiPromptRegistry;
use App\Support\AppointmentCardPdf;
use App\Support\ConsultReport;
use App\Support\Finance\TaxInvoiceDocument;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **متغيّرات النظام تُقرأ من مصدرها لا من نسخٍ منقوشة.**
 *
 * عدد دفعات التقسيط ونسبة الضريبة واسم المكتب وهاتفه وموقعه إعداداتٌ تضبطها الإدارة
 * (`SettingsRegistry` و`Setting::vatRate()`)، لكنّ الشاشات والمستندات والبريد كانت تنقش
 * نسخها: «تقسيط على 3 دفعات»، «ضريبة القيمة المضافة (15%)»، اسمٌ ثالث للمكتب في محضر العميل،
 * وتذييلاتُ PDF تعلو على الاسم المضبوط. فيغيّر المدير الإعداد ويبقى العميل يقرأ القديم.
 *
 * نصف هذا الملفّ **حارس**: يمسح `app/` و`resources/js` و`resources/views` (بعد حذف التعليقات)
 * بحثاً عن تلك النسخ، ويفشل إن عادت واحدةٌ خارج قائمة السماح المعلَّلة أدناه. والنصف الآخر
 * **سلوك**: الخاصيّة المشتركة والمستندات والبريد تعكس الإعداد بعد تغييره.
 */
class SettingsNotHardcodedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * النسخ الممنوعة — لكلٍّ نمطُه وقائمةُ سماحٍ بعدد المرّات الأقصى لكلّ ملفّ، مع السبب.
     * العدد لا الملفّ وحده: سماحُ ملفٍّ كاملٍ يُمرّر نسخةً جديدة تُضاف إليه بصمت.
     *
     * @return array<string, array{pattern:string, allow:array<string,int>}>
     */
    private static function forbidden(): array
    {
        // بيانات النموذج الثابت الأوّل — ثوابت عرضٍ لا تقرؤها شاشةٌ حيّة، مُحتفَظ بها بقرار «لا حذف»
        // (انظر رأس `admin-data.ts`). روابطها الوهميّة تحمل نطاق المكتب القديم.
        $mockData = [
            'resources/js/lib/admin-data.ts' => 3,
            'resources/js/lib/employee-data.ts' => 3,
            'resources/js/lib/lawyer-data.ts' => 1,
            'resources/js/lib/data.ts' => 1,
        ];

        return [
            'نسبة الضريبة منقوشةً في عنوان' => [
                'pattern' => '/\((?:15|١٥)\s*[%٪]\)/u',
                'allow' => [],
            ],
            'افتراض «15» لنسبة الضريبة' => [
                'pattern' => '/(?:vat\w*|rate)\s*(?:\?\?|\|\||=)\s*15\b|vat_rate\'\s*,\s*15\b/i',
                // لا استثناء: `Setting::vatRate()` صار يقرأ افتراض السجلّ، فلا 15 منقوشة في أيّ ملفّ
                'allow' => [],
            ],
            'نسبة الضريبة كسراً منقوشاً' => [
                'pattern' => '/vat\w*\s*[:=]\s*0\.15\b|\*\s*0\.15\b|\b0\.15\s*\*/i',
                'allow' => ['resources/js/lib/admin-data.ts' => 7],
            ],
            'عدد دفعات التقسيط منقوشاً' => [
                'pattern' => '/(?<![\d٠-٩])(?:3|٣)\s*(?:دفعات|أقساط)|ثلاثة?\s+(?:دفعات|أقساط)/u',
                'allow' => [],
            ],
            'افتراض «3» لعدد الدفعات' => [
                'pattern' => '/installments\w*\s*(?:\?\?|\|\|)\s*3\b/i',
                'allow' => [],
            ],
            'موقع المكتب أو هاتفه منقوشاً' => [
                'pattern' => '/salaselbabel\.net|462\s?2277/',
                'allow' => ['app/Support/SettingsRegistry.php' => 2] + $mockData,
            ],
            // **اسمٌ واحد في كلّ مكان** (قرار المالك 2026-09-26): كانت الصفحة الترويجيّة وصفحة الدخول
            // وعنوان التبويب وشعار القائمة وخمس عشرة تعليمةً للنموذج مسموحاً لها بالاسم منقوشاً بوصفه
            // «اسم المنتج»، فلا تغيّره الإدارة إلّا بنشر كود. سقط السماح كلّه إلّا افتراض الإعداد، والنمط
            // يلتقط التهجئات الثلاث التي وُجدت («لمكاتب المحاماة» و«للمحاماة» و«المحاماه»).
            'اسم المكتب منقوشاً' => [
                'pattern' => '/النظام الإداريّ? (?:لمكاتب ال|لل)محاما[ةه]|سلاسل بابل للمحاماة/u',
                'allow' => [
                    // افتراض الإعداد نفسه — المصدر الوحيد
                    'app/Support/SettingsRegistry.php' => 1,
                ],
            ],
            // نوافذ الموعد إعدادات (`session_join_opens_minutes` · `consult_staff_start_minutes` · `office_arrival_minutes`) —
            // النصّ يُبنى منها. النمط يلتقط الرقم والكلمة معاً: فاتت المرحلةَ الثانية «بخمس دقائق» و«بربع ساعة» لأنّها التقطت الرقم وحده
            'نافذة موعدٍ منقوشةً في نصّ' => [
                'pattern' => '/قبل (?:الموعد|موعدها) ب\s*ـ?\s*(?:5|٥|15|١٥)\s*(?:د|دقائق|دقيقة)|بخمس دقائق|بربع ساعة|ربع ساعة قبل/u',
                'allow' => [],
            ],
            // حدّ الرفع ثابتٌ واحد (`UploadLimits` ونظيره `upload-limits.ts`) لا رقمٌ في كلّ متحكّم (قرار المالك 2026-09-30)
            'حدّ رفعٍ منقوش' => [
                'pattern' => '/max:(?:10240|2048)\b|\b(?:2|10) \* 1024 \* 1024|حتى (?:2|10) ?MB/u',
                'allow' => [],
            ],
            '`APP_NAME` بديلاً عن اسم المكتب' => [
                // `VITE_APP_NAME` نظيرُه في الواجهة: اسمٌ يُنقش في الحزمة عند البناء فلا يتبع الإعداد
                'pattern' => '/config\(\s*[\'"]app\.name[\'"]|VITE_APP_NAME/',
                'allow' => [
                    // خاصيّة Inertia القياسيّة `name` — لا تقرؤها شاشة؛ الاسم المعروض `settings.office_name`
                    'app/Http/Middleware/HandleInertiaRequests.php' => 1,
                ],
            ],
        ];
    }

    // ── ١ الحارس ──
    public function test_no_hardcoded_copy_of_a_setting_returns_outside_the_allow_list(): void
    {
        $violations = [];

        foreach (self::forbidden() as $label => $rule) {
            foreach (self::scan($rule['pattern']) as $path => $count) {
                $allowed = $rule['allow'][$path] ?? 0;
                if ($count > $allowed) {
                    $violations[] = "«{$label}» في {$path}: {$count} (المسموح {$allowed})";
                }
            }
        }

        $this->assertSame([], $violations, "نسخٌ منقوشة من إعداداتٍ تضبطها الإدارة — اقرأها من `SettingsRegistry` في الخادم، ومن `useSettings()` في الواجهة:\n".implode("\n", $violations));
    }

    /**
     * عدد مطابقات النمط في كلّ ملفّ بعد حذف التعليقات — التعليقات تشرح لماذا أُزيلت النسخة
     * فتذكرها، وحذفها يمنع الحارس من معاقبة الشرح.
     *
     * @return array<string, int>
     */
    public static function scan(string $pattern): array
    {
        $root = dirname(__DIR__, 2);
        $hits = [];

        foreach (['app', 'resources/js', 'resources/views'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (! preg_match('/\.(php|ts|tsx)$/', $path)) {
                    continue;
                }

                $count = preg_match_all($pattern, self::withoutComments($path, (string) file_get_contents($file->getPathname())));
                if ($count > 0) {
                    $hits[$path] = $count;
                }
            }
        }

        return $hits;
    }

    private static function withoutComments(string $path, string $source): string
    {
        // PHP خالص: المُجزِّئ يعرف التعليق يقيناً (ولا يخلطه بـ`//` داخل نصّ)
        if (str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
            return implode('', array_map(
                fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                token_get_all($source),
            ));
        }

        // Blade وTS/TSX: تعليقات القالب والكتل، ثمّ `//` السطريّة — إلّا ما سبقته نقطتان (`https://`)
        // (`/*` المسبوقة بحرفٍ كـ`image/*` ليست تعليقاً — حذفُها يبتلع ما بعدها حتى أوّل `*/`)
        $source = (string) preg_replace(['/\{\{--.*?--\}\}/s', '/(?<![\w\'"\/])\/\*.*?\*\//s'], '', $source);

        return (string) preg_replace('/(?<![:\'"\\\\])\/\/[^\n]*/', '', $source);
    }

    // ── ٢ الخاصيّة المشتركة ──
    public function test_every_shared_key_is_a_declared_setting(): void
    {
        foreach (HandleInertiaRequests::SHARED_SETTINGS as $key) {
            $this->assertArrayHasKey($key, SettingsRegistry::all(), "«{$key}» في SHARED_SETTINGS وليس في السجلّ");
        }
    }

    public function test_the_shared_settings_prop_reflects_a_changed_setting(): void
    {
        Setting::put('installments_count', 4);
        Setting::put('vat_rate', 5);
        Setting::put('office_name', 'مكتب الاختبار للمحاماة');
        Setting::put('office_phone', '011 000 1111');
        Setting::put('office_url', 'https://office.example/');

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->where('settings.installments_count', 4)
            ->where('settings.vat_rate', 5)
            ->where('settings.office_name', 'مكتب الاختبار للمحاماة')
            ->where('settings.office_phone', '011 000 1111')
            ->where('settings.office_url', 'https://office.example/'));
    }

    /**
     * **عنوان التبويب اسم المكتب من إعداده** (قرار المالك 2026-09-26) — في الصفحة الترويجيّة وصفحة
     * الدخول معاً. كان من `APP_NAME`/`VITE_APP_NAME` المبنيّين في البيئة والحزمة، فلا يتبع الإعداد.
     * `<title>` هنا ما يكتبه الخادم قبل عمل الواجهة؛ وبعده تكتبه دالّة `title` في `app.tsx` من
     * الخاصيّة المشتركة نفسها — فيُفحص أنّها تقرؤها لا نسخةً مبنيّة.
     */
    public function test_the_browser_tab_title_is_the_office_name_from_settings(): void
    {
        $default = (string) SettingsRegistry::field('office_name')['default'];
        Setting::put('office_name', 'مكتب الاختبار للمحاماة');

        foreach (['/', '/login'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<title>مكتب الاختبار للمحاماة</title>', $html, "عنوان {$url}");
            $this->assertStringNotContainsString("<title>{$default}</title>", $html, "عنوان {$url}");
        }

        $this->assertStringContainsString(
            'settings?.office_name',
            (string) file_get_contents(resource_path('js/app.tsx')),
            'دالّة العنوان في الواجهة تقرأ اسم المكتب من الخاصيّة المشتركة',
        );
    }

    /**
     * **المساعد الذكيّ يُعرّف المكتب باسمه المضبوط** — كان الاسم منقوشاً في خمس عشرة تعليمة، فيغيّر
     * المدير الاسم ويبقى النموذج يكتب القديم في ردودٍ تصل العميل. الاسم يُملأ عند بناء التعليمة
     * (`AiPromptRegistry::withOffice`)، فكلّ تعليمةٍ تحمل الاسم تُبنى هنا وتُفحص.
     */
    public function test_ai_system_prompts_carry_the_office_name_from_settings(): void
    {
        $default = (string) SettingsRegistry::field('office_name')['default'];
        Setting::put('office_name', 'مكتب الاختبار للمحاماة');
        $departments = ['القضايا التجارية'];

        $prompts = [
            'ticket.triage' => AiPromptRegistry::ticketTriageSystem($departments),
            'consult.analyze' => AiPromptRegistry::consultAnalyzeSystem([]),
            'execution.analyze' => AiPromptRegistry::executionAnalyzeSystem(),
            'case.classify' => AiPromptRegistry::caseClassifySystem($departments),
            'document.analyze' => AiPromptRegistry::documentAnalyzeSystem(),
            'meeting.summary' => AiPromptRegistry::meetingSummarySystem(),
            'ticket.summary' => AiPromptRegistry::ticketSummarySystem(),
            'case.pleading' => AiPromptRegistry::casePleadingSystem(),
            'consult.summary' => AiPromptRegistry::consultSummarySystem(),
            'assistant.draft/reply_memo' => AiPromptRegistry::assistantDraftSystem('reply_memo', 'مذكرة'),
            'assistant.draft/default' => AiPromptRegistry::assistantDraftSystem('lawahe', 'مذكرة'),
            'chat.reply' => AiPromptRegistry::chatReplySystem(),
        ];

        foreach ($prompts as $id => $text) {
            $this->assertStringContainsString('«مكتب الاختبار للمحاماة»', $text, "«{$id}» لا تحمل الاسم المضبوط");
            $this->assertStringNotContainsString($default, $text, "«{$id}» ما زالت تحمل الاسم الافتراضيّ");
            $this->assertStringNotContainsString(AiPromptRegistry::OFFICE, $text, "«{$id}» وصلها موضع الاسم بلا ملء");
        }
    }

    /**
     * **ولا يصل النموذجَ موضعُ الاسم فارغاً.** التعليمات المكتوبة خارج السجلّ (في `LegalAiService`)
     * لا تُبنى في اختبارٍ بلا مزوّدٍ ومستند؛ فالحارس مصدريّ: كلّ موضع `{office}` في `app/` يقابله نداء
     * `withOffice(` في الملفّ نفسه (وفي السجلّ يتقابل الثابت `OFFICE` مع تعريف الدالّة). موضعٌ بلا
     * نداءٍ يعني أنّ النموذج سيقرأ «أنت مساعدٌ في {office}» حرفيّاً.
     */
    public function test_every_office_placeholder_is_filled_where_it_is_written(): void
    {
        // الخادم وحده: `${office}` في قالب نصّيّ بالواجهة متغيّرٌ لا موضع تعليمة
        $inApp = fn (string $path) => str_starts_with($path, 'app/');
        $tokens = array_filter(self::scan('/'.preg_quote(AiPromptRegistry::OFFICE, '/').'/'), $inApp, ARRAY_FILTER_USE_KEY);
        $fills = array_filter(self::scan('/\bwithOffice\(/'), $inApp, ARRAY_FILTER_USE_KEY);

        $this->assertNotSame([], $tokens);
        foreach ($tokens as $path => $count) {
            $this->assertSame($count, $fills[$path] ?? 0, "{$path}: {$count} موضعاً لاسم المكتب مقابل ".($fills[$path] ?? 0).' نداء withOffice');
        }
    }

    // ── ٣ المستندات ──
    public function test_printed_documents_carry_the_office_identity_from_settings(): void
    {
        $default = (string) SettingsRegistry::field('office_name')['default'];
        Setting::put('office_name', 'مكتب الاختبار للمحاماة');
        Setting::put('office_phone', '011 000 1111');
        Setting::put('office_url', 'https://office.example/');

        $card = AppointmentCardPdf::html([
            'no' => 'AP-1', 'type' => 'حضوري', 'day' => 'الأحد', 'time' => '10:00', 'place' => 'المقرّ',
            'client' => 'عميل', 'lawyer' => 'محامٍ', 'consultRef' => 'CN-1', 'address' => 'الرياض',
            'paid' => true, 'payLabel' => 'مدفوع',
        ]);
        $this->assertStringContainsString('مكتب الاختبار للمحاماة', $card);
        $this->assertStringContainsString('https://office.example/ · 011 000 1111', $card);
        $this->assertStringNotContainsString($default, $card);

        $client = User::factory()->create(['role' => Role::Client]);
        $invoice = Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-S-'.uniqid(), 'description' => 'رسوم استشارة',
            'amount' => 1150, 'subtotal' => 1000, 'vat_rate' => 15, 'vat_amount' => 150,
            'status' => InvoiceStatus::Paid->value, 'tone' => 'b-green', 'due_label' => '—',
            'paid' => true, 'paid_at' => now(), 'issued_at' => now(),
        ]);
        $html = TaxInvoiceDocument::html($invoice);
        // الاسم المضبوط في الفاتورة (البائع ونصّ الشعار البديل) — ولا شريط تذييل (طلب المالك 2026-09-28)
        $this->assertStringContainsString('مكتب الاختبار للمحاماة', $html);
        $this->assertStringNotContainsString('cf-foot', $html);
        $this->assertStringNotContainsString($default, $html, 'الاسم الافتراضيّ لا يعلو على الاسم المضبوط');

        $consult = $this->pricedConsult($client, 1000, 150);
        $doc = json_encode(ConsultReport::doc($consult, $client->name), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('مكتب الاختبار للمحاماة', (string) $doc);
        $this->assertStringNotContainsString($default, (string) $doc);
    }

    /**
     * نسبة الضريبة المطبوعة هي ما طُبّق على الاستشارة (مستنتجةً من مبلغيها المجمَّدين) — لا «١٥٪»
     * منقوشة، ولا الإعداد الحاليّ إن تغيّر بعد التسعير.
     */
    public function test_the_consult_vat_label_is_the_rate_it_was_priced_at(): void
    {
        Setting::put('vat_rate', 15);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->pricedConsult($client, 1000, 50);

        $this->assertSame(5, $consult->vatRate());
        $this->assertSame(5, $consult->toClientCard()['vatRate']);
        $this->assertStringContainsString('الضريبة (5٪)', (string) json_encode(ConsultReport::doc($consult, $client->name), JSON_UNESCAPED_UNICODE));
    }

    // ── ٤ البريد ──
    public function test_mail_carries_the_office_name_from_settings_not_app_name(): void
    {
        Setting::put('office_name', 'مكتب الاختبار للمحاماة');

        $mail = new VerificationCodeMail('123456', 'عميل');

        $mail->assertHasSubject('رمز التحقّق — مكتب الاختبار للمحاماة');
        $mail->assertSeeInHtml('مكتب الاختبار للمحاماة');
        $mail->assertDontSeeInHtml((string) SettingsRegistry::field('office_name')['default']);
    }

    private function pricedConsult(User $client, int $price, int $vat): Consult
    {
        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-SET-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => ConsultStatus::AwaitingPayment->value,
            'lawyer' => 'المستشار المختص', // العمود إلزاميّ؛ ونائبٌ نصّيّ لا اسمُ شخص
            'tone' => 'b-amber', 'price' => $price, 'vat' => $vat, 'total' => $price + $vat, 'priced_at' => now(),
        ]);
    }
}
