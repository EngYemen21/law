<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Support\AppointmentCardPdf;
use App\Support\DocumentVerification;
use App\Support\Finance\TaxInvoiceDocument;
use App\Support\Finance\ZatcaQr;
use App\Support\NativePdf;
use App\Support\Qr;
use App\Support\ReportPrint;
use App\Support\SummaryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **كلّ رمز استجابةٍ في المشروع حقيقيّ ويحيل إلى ما يَعِد به.**
 *
 * كان المولّد نقشاً زخرفيّاً لا يُمسح على بطاقة الموعد والتقارير وعرض التنفيذ، والفاتورة
 * الضريبيّة بلا رمز الهيئة. فيحرس هذا الملفّ أربعة أشياء:
 *
 * ١. **رمز الهيئة** (`ZatcaQr`) — يُفكّ هنا بمحلّلٍ مستقلّ (لا بالمرمِّز نفسه) فتُطابَق الوسوم
 *    الخمسة وقيمها بالبايت، والوحدات ريالاتٌ لا هللات؛ ولا رمز بلا رقمٍ ضريبيّ.
 * ٢. **الرمز رمزٌ صالح** (`Qr`) — مستوى التصحيح M ومعلومات الصيغة سليمة بنسختيها، ومنطقة هادئة ٤.
 * ٣. **كلّ مستندٍ يحمل الحمولة الصحيحة بعينها** — يُصيَّر بالمحرّك الاحتياطيّ (`NativePdf`) الذي
 *    يكتب الرمز أوامرَ PDF غير مضغوطة، فتُقارَن بأوامر النصّ المتوقَّع.
 * ٤. **رابط التحقّق يعمل ويرفض المعدَّل** — بصفحةٍ عربيّة، لا صفحة خطأ.
 */
class QrCodeTest extends TestCase
{
    use RefreshDatabase;

    private const VAT_NUMBER = '300000000000003';

    private const OFFICE = 'مكتب الاختبار للمحاماة';

    // ═════════════ ١ — رمز هيئة الزكاة والضريبة والجمارك ═════════════

    public function test_the_zatca_payload_decodes_to_the_five_tags_with_exact_values(): void
    {
        $invoice = $this->invoice();

        $tags = $this->decodeTlv((string) ZatcaQr::payload($invoice));

        $this->assertSame([1, 2, 3, 4, 5], array_keys($tags), 'الوسوم الخمسة بترتيبها');
        $this->assertSame(self::OFFICE, $tags[1]);
        $this->assertSame(self::VAT_NUMBER, $tags[2]);
        // 12:30 بتوقيت الرياض (توقيت التطبيق) = 09:30 بالتوقيت العالميّ
        $this->assertSame('2026-09-20T09:30:00Z', $tags[3]);
        // **ريالات**: الفاتورة 1150 ريالاً بضريبة 150 — لا 11.50 ولا 115000
        $this->assertSame('1150.00', $tags[4]);
        $this->assertSame('150.00', $tags[5]);
    }

    /** الطول بالبايت لا بالحروف — الاسم العربيّ حرفه بايتان في UTF-8. */
    public function test_the_tlv_length_byte_counts_utf8_bytes_not_characters(): void
    {
        $raw = base64_decode((string) ZatcaQr::payload($this->invoice()), true);

        $this->assertNotFalse($raw);
        $this->assertSame(1, ord($raw[0]));
        $this->assertSame(strlen(self::OFFICE), ord($raw[1]));
        $this->assertGreaterThan(mb_strlen(self::OFFICE), ord($raw[1]));
    }

    /** الصفّ القديم بلا أعمدة ضريبة: الرمز يتبع التفصيل المطبوع نفسه (عكس الحساب). */
    public function test_the_zatca_totals_follow_the_printed_breakdown_for_a_legacy_invoice(): void
    {
        $invoice = $this->invoice(['subtotal' => null, 'vat_amount' => null, 'vat_rate' => null]);
        $money = $invoice->taxBreakdown();

        $tags = $this->decodeTlv((string) ZatcaQr::payload($invoice));

        $this->assertSame(number_format($money['amount'], 2, '.', ''), $tags[4]);
        $this->assertSame(number_format($money['vat_amount'], 2, '.', ''), $tags[5]);
        $this->assertSame('1150.00', $tags[4]);
    }

    public function test_the_tax_invoice_document_carries_exactly_that_payload(): void
    {
        $invoice = $this->invoice();
        $payload = (string) ZatcaQr::payload($invoice);

        $html = TaxInvoiceDocument::html($invoice);

        $this->assertStringContainsString(TaxInvoiceDocument::ZATCA_TITLE, $html);
        $this->assertStringContainsString(Qr::svg($payload, 120, TaxInvoiceDocument::ZATCA_TITLE), $html);
        $this->assertSame(1, substr_count($html, 'data-qr='), 'رمزٌ واحد على الفاتورة');
    }

    /** **لا رقم ضريبيّ ⇒ لا رمز ولا سطر** — معاً، لا أحدهما. */
    public function test_an_empty_vat_number_prints_neither_the_qr_nor_the_vat_line(): void
    {
        $invoice = $this->invoice([], vatNumber: '');

        $this->assertNull(ZatcaQr::payload($invoice));

        $html = TaxInvoiceDocument::html($invoice);
        $this->assertStringNotContainsString('data-qr=', $html);
        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringNotContainsString(TaxInvoiceDocument::ZATCA_TITLE, $html);
        $this->assertStringNotContainsString(TaxInvoiceDocument::VAT_NUMBER_LABEL, $html);
        $this->assertStringContainsString(TaxInvoiceDocument::TOTAL_LABEL, $html);
    }

    public function test_a_cancelled_invoice_carries_no_tax_qr(): void
    {
        $invoice = $this->invoice(['status' => InvoiceStatus::Cancelled->value, 'paid' => false]);

        $this->assertNull(ZatcaQr::payload($invoice));
        $this->assertStringNotContainsString('data-qr=', TaxInvoiceDocument::html($invoice));
    }

    /** الفاتورة المنزَّلة فعلاً (المسار لا الصنف وحده) تحمل الرمز نفسه. */
    public function test_the_downloaded_invoice_pdf_draws_the_zatca_code(): void
    {
        config(['pdf.engine' => 'native']);
        $invoice = $this->invoice();

        $pdf = $this->actingAs($invoice->user)->get(route('invoices.pdf', $invoice))->assertOk()->getContent();

        $this->assertStringContainsString($this->qrOps((string) ZatcaQr::payload($invoice)), (string) $pdf);
    }

    // ═════════════ ٢ — الرمز رمزٌ صالح ═════════════

    public function test_the_generated_code_is_a_valid_level_m_symbol_with_a_quiet_zone(): void
    {
        $text = 'https://office.example/verify/appointment/AP-1?signature='.str_repeat('a', 64);
        $rows = Qr::matrix($text);
        $n = count($rows);

        // الحجم 17 + 4×الإصدار، والوحدة الداكنة الثابتة في (8، 4v+9)
        $this->assertSame(0, ($n - 17) % 4);
        $version = intdiv($n - 17, 4);
        $this->assertTrue($rows[4 * $version + 9][8], 'الوحدة الداكنة الثابتة');

        // معلومات الصيغة: النسختان متطابقتان، وصالحتان (BCH)، والمستوى M (البتّان 00)
        [$copy1, $copy2] = $this->formatInfo($rows);
        $this->assertSame($copy1, $copy2);
        $unmasked = $copy1 ^ 0x5412;
        $data = $unmasked >> 10;
        $this->assertSame($unmasked & 0x3FF, $this->bch($data), 'رمز BCH لمعلومات الصيغة');
        $this->assertSame(0, $data >> 3, 'مستوى تصحيح الخطأ M');

        // SVG: المنطقة الهادئة ٤ وحدات، داكنٌ على أبيض، والنصّ المُرمَّز معلَن
        $svg = Qr::svg($text);
        $this->assertStringContainsString('viewBox="0 0 '.($n + 8).' '.($n + 8).'"', $svg);
        $this->assertStringContainsString('fill="#FFFFFF"', $svg);
        $this->assertStringContainsString('fill="#000000"', $svg);
        $this->assertStringContainsString('data-qr="'.e($text).'"', $svg);
        preg_match_all('/M(\d+) (\d+)/', $svg, $m);
        $this->assertSame(4, min(array_map('intval', $m[1])));
        $this->assertSame(4, min(array_map('intval', $m[2])));
        $this->assertLessThanOrEqual($n + 3, max(array_map('intval', $m[2])));

        // يُرمِّز النصّ لا بذرة: نصّان مختلفان ⇒ مصفوفتان مختلفتان
        $this->assertNotSame($rows, Qr::matrix($text.'b'));
    }

    // ═════════════ ٣ — كلّ مستندٍ يحمل الحمولة الصحيحة ═════════════

    public function test_the_appointment_card_pdf_and_screen_image_carry_the_signed_verify_url(): void
    {
        config(['pdf.engine' => 'native']);
        [$client, $appointment] = $this->appointment();
        $url = DocumentVerification::url(DocumentVerification::APPOINTMENT, 'AP-QR-1');

        $pdf = $this->actingAs($client)->get(route('appointments.card', $appointment))->assertOk()->getContent();
        $this->assertStringContainsString($this->qrOps($url), (string) $pdf);

        $svg = $this->actingAs($client)->get(route('appointments.qr', $appointment))->assertOk();
        $this->assertStringStartsWith('image/svg+xml', (string) $svg->headers->get('Content-Type'));
        $this->assertSame($url, $this->encodedText((string) $svg->getContent()));

        // وبطاقة الشاشة تعدّ ما يقع فعلاً — لا «امسح لتأكيد الحضور»
        $html = AppointmentCardPdf::html($this->cardData($url));
        $this->assertStringContainsString('امسح للتحقّق من البطاقة', $html);
        $this->assertStringNotContainsString('امسح لتأكيد الحضور', $html);
    }

    public function test_a_foreign_client_gets_no_appointment_qr(): void
    {
        [, $appointment] = $this->appointment();
        $intruder = User::factory()->create(['role' => Role::Client]);

        $response = $this->actingAs($intruder)->get(route('appointments.qr', $appointment));

        // الرفض نفسه لا شكله (403 أو تحويلٌ برسالة — يوحّده معالج الأخطاء): المهمّ ألّا يصل الرمز
        $this->assertFalse($response->isSuccessful());
        $this->assertStringNotContainsString('data-qr=', (string) $response->getContent());
    }

    public function test_the_consult_report_pdf_carries_the_signed_verify_url(): void
    {
        config(['pdf.engine' => 'native']);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = $this->consult($client);

        $pdf = $this->actingAs($client)->get(route('consults.report', $consult))->assertOk()->getContent();

        $url = DocumentVerification::url(DocumentVerification::CONSULT, 'CN-QR-1');
        $this->assertStringContainsString($this->qrOps($url), (string) $pdf);
    }

    public function test_the_execution_offer_pdf_carries_the_signed_verify_url(): void
    {
        config(['pdf.engine' => 'native']);
        $client = User::factory()->create(['role' => Role::Client]);
        $execution = $this->execution($client);

        $pdf = $this->actingAs($client)->get(route('exec-flow.offer.pdf', $execution))->assertOk()->getContent();

        $url = DocumentVerification::url(DocumentVerification::EXECUTION, 'EXE-QR-1');
        $this->assertStringContainsString($this->qrOps($url), (string) $pdf);
    }

    public function test_the_summary_report_carries_the_signed_verify_url_and_no_approval_claim(): void
    {
        [$ticket, $summary] = $this->summary(approved: true);

        $doc = SummaryReport::doc($ticket, $summary, 'العميل', 'المستشار');
        $html = ReportPrint::html($doc);

        $url = DocumentVerification::url(DocumentVerification::SUMMARY, 'SB-QR-1');
        $this->assertSame($url, $doc['approval']['qr']);
        $this->assertSame($url, $this->encodedText($html));
        $this->assertStringNotContainsString('approved=1', $url, 'الاعتماد يُقرأ من السجلّ لا من الرابط');
    }

    // ═════════════ ٤ — صفحة التحقّق ═════════════

    public function test_the_verify_page_confirms_the_document_without_personal_data(): void
    {
        [$client] = $this->appointment();

        $response = $this->get($this->relative(DocumentVerification::url(DocumentVerification::APPOINTMENT, 'AP-QR-1')));

        $response->assertOk()
            ->assertSee('وثيقةٌ صادرة من', false)
            ->assertSee('AP-QR-1')
            ->assertSee('بطاقة موعد')
            ->assertDontSee($client->name)
            ->assertDontSee('أ. سارة القحطاني');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** نسخةٌ طُبعت قبل الاعتماد تبقى «لم يُعتمد بعد» — الصفحة تقرأ السجلّ الآن. */
    public function test_the_summary_verify_page_reports_the_live_approval_state(): void
    {
        [, $summary] = $this->summary(approved: false);
        $path = $this->relative(DocumentVerification::url(DocumentVerification::SUMMARY, 'SB-QR-1'));

        $this->get($path)->assertOk()->assertSee('لم يُعتمد بعد');

        $summary->update(['status' => 'approved', 'approved_at' => now()]);
        $this->get($path)->assertOk()->assertSee('معتمد')->assertDontSee('لم يُعتمد بعد');
    }

    public function test_a_tampered_or_unsigned_link_is_refused_with_an_arabic_page(): void
    {
        $this->appointment();
        $this->appointment('AP-QR-2');
        $signed = $this->relative(DocumentVerification::url(DocumentVerification::APPOINTMENT, 'AP-QR-1'));

        // المرجع مُبدَّل والتوقيع القديم باقٍ
        $this->get(str_replace('AP-QR-1', 'AP-QR-2', $signed))
            ->assertForbidden()
            ->assertSee('تعذّر التحقّق')
            ->assertDontSee('AP-QR-2');

        // بلا توقيع أصلاً
        $this->get('/verify/appointment/AP-QR-1')->assertForbidden()->assertSee('تعذّر التحقّق');
    }

    public function test_a_signed_link_to_a_missing_document_says_so(): void
    {
        $this->get($this->relative(DocumentVerification::url(DocumentVerification::CONSULT, 'CN-NOPE')))
            ->assertNotFound()
            ->assertSee('لا توجد في سجلّات المكتب');
    }

    /** الأصل من `APP_URL` كما في روابط البريد — لا مضيف الطلب ولا `office_url`. */
    public function test_the_verify_url_is_absolute_on_the_app_url(): void
    {
        config(['app.url' => 'https://law.example']);

        $url = DocumentVerification::url(DocumentVerification::APPOINTMENT, 'AP-QR-1');

        $this->assertMatchesRegularExpression('#^https://law\.example/verify/appointment/AP-QR-1\?signature=[0-9a-f]{64}$#', $url);
    }

    // ═════════════ ٥ — حارس: مولّدٌ واحد ولا نقش زخرفيّ ═════════════

    public function test_there_is_one_qr_generator_and_no_decorative_pattern_left(): void
    {
        $offenders = [];
        foreach ([app_path(), resource_path('js'), resource_path('views')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! preg_match('/\.(php|tsx?)$/', $file->getFilename())) {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

                // المكتبة تُنادى من المولّد الوحيد فقط
                if (str_contains($src, 'BaconQrCode\\') && ! str_ends_with($rel, 'Support'.DIRECTORY_SEPARATOR.'Qr.php')) {
                    $offenders[] = $rel.' ينادي مكتبة الرمز مباشرةً';
                }
                // بقايا النقش الزخرفيّ: البذرة، والمكوّن الأماميّ، وأمر المسح الكاذب
                foreach (['qrSeed', 'qrRects', '<Qr ', 'امسح لتأكيد الحضور'] as $needle) {
                    if (str_contains($src, $needle)) {
                        $offenders[] = $rel.' يحوي '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
        $this->assertSame([], glob(public_path('images/*qr*')) ?: [], 'لا صورة رمزٍ ثابتة');
    }

    // ═════════════ أدوات ═════════════

    /**
     * محلّل TLV مستقلّ عن `ZatcaQr` — لا يُختبَر المرمِّز بنفسه.
     *
     * @return array<int, string>
     */
    private function decodeTlv(string $base64): array
    {
        $raw = base64_decode($base64, true);
        $this->assertNotFalse($raw, 'Base64 صالح');

        $tags = [];
        $i = 0;
        while ($i < strlen($raw)) {
            $tag = ord($raw[$i]);
            $len = ord($raw[$i + 1]);
            $tags[$tag] = substr($raw, $i + 2, $len);
            $this->assertSame($len, strlen($tags[$tag]), "طول الوسم {$tag}");
            $i += 2 + $len;
        }

        return $tags;
    }

    /** أوامر PDF التي يرسم بها `NativePdf` رمز هذا النصّ بعينه. */
    private function qrOps(string $text): string
    {
        $pdf = NativePdf::build('<div>'.Qr::svg($text).'</div>', 'x');
        $start = strpos($pdf, "% qr\n");
        $this->assertNotFalse($start);

        return substr($pdf, $start, strpos($pdf, "Q\n", $start) + 2 - $start);
    }

    private function encodedText(string $html): string
    {
        $this->assertSame(1, preg_match('/data-qr="([^"]*)"/', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function relative(string $absolute): string
    {
        $parts = parse_url($absolute);

        return ($parts['path'] ?? '').'?'.($parts['query'] ?? '');
    }

    /** @return array{0:int,1:int} معلومات الصيغة بنسختيها (بتّ i في الإحداثيّ i) */
    private function formatInfo(array $rows): array
    {
        $n = count($rows);
        $coords = [[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]];
        $a = 0;
        $b = 0;
        foreach ($coords as $i => [$x, $y]) {
            $a |= ($rows[$y][$x] ? 1 : 0) << $i;
            [$x2, $y2] = $i < 8 ? [$n - $i - 1, 8] : [8, $n - 7 + ($i - 8)];
            $b |= ($rows[$y2][$x2] ? 1 : 0) << $i;
        }

        return [$a, $b];
    }

    private function bch(int $data): int
    {
        $v = $data << 10;
        for ($bit = 14; $bit >= 10; $bit--) {
            if ($v & (1 << $bit)) {
                $v ^= 0x537 << ($bit - 10);
            }
        }

        return $v;
    }

    private function invoice(array $attrs = [], string $vatNumber = self::VAT_NUMBER): Invoice
    {
        Setting::put('vat_rate', 15);
        Setting::put('office_name', self::OFFICE);
        Setting::put('office_vat_number', $vatNumber);
        $client = User::factory()->create(['role' => Role::Client]);

        return Invoice::create(array_merge([
            'user_id' => $client->id, 'number' => 'INV-QR-'.uniqid(), 'description' => 'رسوم استشارة',
            'amount' => 1150, 'subtotal' => 1000, 'vat_rate' => 15, 'vat_amount' => 150,
            'status' => InvoiceStatus::Paid->value, 'tone' => 'b-green', 'due_label' => '—',
            'paid' => true, 'paid_at' => '2026-09-20 13:00:00', 'issued_at' => '2026-09-20 12:30:00',
        ], $attrs));
    }

    /** @return array{0:User,1:Appointment} */
    private function appointment(string $extId = 'AP-QR-1'): array
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'عميل سرّيّ الاسم']);
        $appointment = Appointment::create([
            'user_id' => $client->id, 'ext_id' => $extId, 'type' => 'استشارة حضورية', 'ico' => 'office',
            'lawyer' => 'أ. سارة القحطاني', 'day' => 'الاثنين 10 أغسطس', 'time' => '11:00',
            'place' => 'الرياض — حي العليا', 'status' => 'مؤكد', 'tone' => 'b-green', 'when_kind' => 'up',
        ]);

        return [$client, $appointment];
    }

    /** @return array<string, mixed> */
    private function cardData(string $url): array
    {
        return [
            'no' => 'AP-QR-1', 'type' => 'حضوري', 'day' => 'الأحد', 'time' => '10:00', 'place' => 'المقرّ',
            'client' => 'عميل', 'lawyer' => 'محامٍ', 'consultRef' => 'CN-1', 'address' => 'الرياض',
            'paid' => true, 'payLabel' => 'مدفوع', 'qr' => $url,
        ];
    }

    private function consult(User $client): Consult
    {
        return Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-QR-1', 'subject' => 'نزاع تجاري', 'specialty' => 'القضايا التجارية',
            'status' => 'جديدة', 'session' => 'بانتظار الجلسة', 'channel' => 'مرئية', 'lawyer' => 'أ. سارة القحطاني',
            'when_label' => 'الاثنين 10 أغسطس · 11:00', 'price' => 450, 'vat' => 68, 'total' => 518,
            'priced_at' => now(), 'paid_at' => now(),
        ]);
    }

    private function execution(User $client): Execution
    {
        return Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-QR-1', 'subject' => 'تنفيذ حكم مالي', 'status' => 'عرض الخدمة',
            'tone' => 'b-blue', 'stage' => 5, 'sanad' => 'حكم قضائي', 'fee' => 2500, 'vat' => 375, 'fee_approved' => true,
        ]);
    }

    /** @return array{0:Ticket,1:TicketSummary} */
    private function summary(bool $approved): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-QR-1', 'type' => 'نزاع تجاري',
            'subject' => 'مطالبة', 'status' => 'مكتملة', 'tone' => 'b-green',
        ]);
        $summary = TicketSummary::create([
            'ticket_id' => $ticket->id, 'case_summary' => 'ملخّص.', 'facts' => 'وقائع.',
            'key_points' => 'توصيات.', 'attachments_summary' => 'مرفقات.',
            'status' => $approved ? 'approved' : 'awaiting_lawyer', 'approved_at' => $approved ? now() : null,
            'result_status' => $approved ? 'approved' : 'none', 'ai_generated' => true,
        ]);

        return [$ticket, $summary];
    }
}
