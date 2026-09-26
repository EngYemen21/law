<?php

namespace Tests\Feature;

use App\Domain\Journey\Transitions\Consult\RescheduleConsult;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Setting;
use App\Models\User;
use App\Services\IcalendarService;
use App\Support\ArabicCount;
use App\Support\CaseFee;
use App\Support\ExecFee;
use App\Support\Finance\InvoiceDue;
use App\Support\LawyerAvailability;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **مهل الفواتير، وساعات الحجز وطول الشريحة، وسياسة إعادة الجدولة، وعنوان المكتب — إعداداتٌ لا نسخ.**
 *
 * كانت أرقاماً منقوشة في مواضع استعمالها، وبعضها بنسخٍ تتخالف: شاشتا الاستشارات تعدّان
 * «متأخّراً» بحدّين (100 و120)، وشبكة الموظّف ٠٩–٢٢ والمحرّك ٠٠–٢٣، ومدّة الاستشارة
 * الاحتياطيّة ٤٥ والشريحة ٦٠. صار لكلٍّ مفتاحٌ في `SettingsRegistry` وقارئٌ واحد.
 *
 * نصف الملفّ **سلوك** (تغيير الإعداد يغيّر ما يُنشأ بعده)، ونصفه **حارس** يمنع عودة النسخ
 * المنقوشة — بماسح `SettingsNotHardcodedTest::scan` نفسه (بعد حذف التعليقات).
 */
class ConsultBillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function consult(User $client, array $attrs = []): Consult
    {
        return Consult::create(array_merge([
            'user_id' => $client->id, 'ref' => 'CN-SET-'.uniqid(), 'subject' => 'نزاع تجاري',
            'channel' => 'مرئية', 'lawyer' => '—', 'day' => '—', 'time' => '—', 'when_label' => '—',
            'session' => 'بانتظار الجلسة', 'status' => 'مؤكد', 'paid_at' => now()->subDay(),
        ], $attrs));
    }

    // ══ الافتراضات ══

    /** الافتراض في السجلّ هو الثابت المُعلَن — والسلوك قبل التغيير هو السلوك قبل هذا العمل. */
    public function test_defaults_are_the_constants_and_values_they_replace(): void
    {
        $f = SettingsRegistry::all();

        $this->assertSame(RescheduleConsult::LIMIT, $f['consult_reschedule_limit']['default']);
        $this->assertSame(Consult::RESCHEDULE_REQUEST_NOTICE_MINUTES, $f['consult_reschedule_notice_minutes']['default']);
        $this->assertSame(LawyerAvailability::SLOT_MIN, $f['consult_slot_minutes']['default']);
        $this->assertSame(LawyerAvailability::WORK_START, $f['consult_day_start']['default']);
        $this->assertSame(LawyerAvailability::WORK_END, $f['consult_day_end']['default']);

        // مهل الفواتير كما كانت منقوشة: ٣ للاستشارة، ١٤ للقضيّة، ٣ للتنفيذ، ٧ للتحصيل، ٣ للدفعة الأولى، ٣٠ بين الأقساط
        $this->assertSame(
            [3, 14, 3, 7, 3, 30],
            array_map(fn ($k) => SettingsRegistry::int($k), ['invoice_due_days_consult', 'invoice_due_days_case', 'invoice_due_days_exec', 'invoice_due_days_collection', 'installment_first_due_days', 'installment_interval_days']),
        );

        // العنوان والمدينة من البيئة ما لم تضبطهما الإدارة — نشرٌ ضبط `OFFICE_ADDRESS` لا يرتدّ
        config(['office.address' => 'جدة — حي الروضة']);
        $this->assertSame('جدة — حي الروضة', SettingsRegistry::str('office_address'));
        Setting::put('office_address', 'الدمام — حي الشاطئ');
        $this->assertSame('الدمام — حي الشاطئ', SettingsRegistry::str('office_address'), 'ما حفظته الإدارة يعلو على البيئة');
    }

    // ══ مهل الفواتير ══

    public function test_the_arabic_due_label_follows_the_number(): void
    {
        $this->assertSame('خلال 3 أيام', InvoiceDue::in(3)['due_label']);
        $this->assertSame('خلال 14 يوماً', InvoiceDue::in(14)['due_label']);
        $this->assertSame('خلال يومين', InvoiceDue::in(2)['due_label']);
        $this->assertSame('خلال يوم واحد', InvoiceDue::in(1)['due_label']);
        $this->assertSame('خلال 100 يوم', InvoiceDue::in(100)['due_label']);
        $this->assertSame('24 ساعة', ArabicCount::hours(24));
        $this->assertSame('مرّتين', ArabicCount::times(2));
    }

    public function test_a_new_consult_invoice_takes_the_configured_due_days(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        Setting::put('invoice_due_days_consult', 5);

        $consult = $this->consult($client, ['status' => 'بانتظار التسعير', 'paid_at' => null]);
        $this->actingAs($admin)->post(route('admin.consults.price', $consult), ['price' => 400])->assertRedirect();

        $invoice = $consult->fresh()->invoice;
        $this->assertSame(now()->addDays(5)->toDateString(), $invoice->due_at->toDateString());
        $this->assertSame('خلال 5 أيام', $invoice->due_label, 'النصّ والتاريخ من رقمٍ واحد');
    }

    /**
     * **الدفعة الأولى بمهلتها المستقلّة، وما بعدها بفاصل الأقساط** (قرار المالك 2026-09-26: «اجعل الإدارة
     * تحدّد»). ومهلة الفاتورة الكاملة لا تمسّها: تغييرها هنا إلى ٤٠ لا يظهر في الخطّة.
     */
    public function test_case_installments_follow_the_first_due_days_and_the_interval(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Setting::put('invoice_due_days_case', 40);
        Setting::put('installment_first_due_days', 10);
        Setting::put('installment_interval_days', 15);

        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-SET-1', 'type' => 'تجاري', 'title' => 'قضية', 'court' => 'المحكمة',
            'status' => 'بانتظار سداد الأتعاب', 'tone' => 'b-amber', 'fee' => 9000, 'fee_status' => 'pending_payment', 'pleading_status' => 'none',
        ]);
        Invoice::create([
            'user_id' => $client->id, 'case_id' => $case->id, 'number' => 'INV-SET-1', 'description' => 'أتعاب القضية',
            'amount' => 9000, 'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'خلال 14 يوماً', 'paid' => false,
        ]);

        $this->assertNotNull(CaseFee::openInstallmentPlan($case->fresh()));

        $plan = Invoice::where('case_id', $case->id)->orderBy('installment_no')->get();
        $this->assertSame(
            [now()->addDays(10)->toDateString(), now()->addDays(15)->toDateString(), now()->addDays(30)->toDateString()],
            $plan->map(fn (Invoice $i) => $i->due_at->toDateString())->all(),
        );
        $this->assertSame(['خلال 10 أيام', 'خلال 15 يوماً', 'خلال 30 يوماً'], $plan->pluck('due_label')->all());
    }

    public function test_a_collection_fee_invoice_takes_the_configured_due_days(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Setting::put('invoice_due_days_collection', 4);

        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-SET-'.uniqid(), 'subject' => 'تنفيذ حكم',
            'status' => 'قيد التنفيذ', 'tone' => 'b-amber', 'stage' => 8, 'amount' => 120000,
            'fee' => 0, 'vat' => 0, 'fee_approved' => true, 'fee_mode' => 'percent', 'collection_fee_pct' => 5,
        ]);

        $invoice = ExecFee::issueCollectionFee($exec, 10000);

        $this->assertSame(now()->addDays(4)->toDateString(), $invoice->due_at->toDateString());
        $this->assertSame('خلال 4 أيام', $invoice->due_label);
    }

    public function test_the_exec_fee_due_reads_its_own_setting(): void
    {
        Setting::put('invoice_due_days_exec', 9);

        $this->assertSame(['due_label' => 'خلال 9 أيام', 'due_at' => now()->addDays(9)->toDateString()], InvoiceDue::execFee());
    }

    // ══ ساعات الحجز وطول الشريحة ══

    public function test_slots_are_generated_from_the_configured_hours_and_length(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $day = now()->addDays(2)->toDateString();

        // الافتراض: اليوم كلّه بساعة — الأربع والعشرون نفسها قبل هذا العمل
        $this->assertCount(24, LawyerAvailability::slotsFor($lawyer->id, $day));

        Setting::put('consult_day_start', 9);
        Setting::put('consult_day_end', 17);
        Setting::put('consult_slot_minutes', 30);

        $slots = LawyerAvailability::slotsFor($lawyer->id, $day);
        $this->assertCount(16, $slots);
        $this->assertSame('09:00', $slots[0]['time']);
        $this->assertSame('09:30', $slots[1]['time']);
        $this->assertSame('16:30', $slots[15]['time'], 'لا شريحة تبدأ ما لم تنتهِ قبل نهاية الساعات');
        $this->assertSame(30, LawyerAvailability::slotMinutes());
    }

    public function test_a_corrupt_hours_pair_falls_back_instead_of_closing_booking(): void
    {
        Setting::put('consult_day_start', 20);
        Setting::put('consult_day_end', 8);

        $this->assertSame([LawyerAvailability::WORK_START, LawyerAvailability::WORK_END], LawyerAvailability::workHours());
    }

    public function test_the_end_hour_must_come_after_the_start_hour(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.settings.update'), ['consult_day_start' => 10, 'consult_day_end' => 9])
            ->assertSessionHasErrors('consult_day_end');
        $this->assertNull(Setting::get('consult_day_start'), 'لا يُحفظ نصف البطاقة');

        // والحقل الواحد يُقارن بالمحفوظ للآخر — لا يمرّ لأنّ جاره غاب عن الطلب
        Setting::put('consult_day_start', 12);
        $this->actingAs($admin)->post(route('admin.settings.update'), ['consult_day_end' => 11])
            ->assertSessionHasErrors('consult_day_end');

        $this->actingAs($admin)->post(route('admin.settings.update'), ['consult_day_end' => 20])
            ->assertSessionHasNoErrors();
        $this->assertSame(20, SettingsRegistry::int('consult_day_end'));
    }

    /**
     * **طول الشريحة مسافةُ حجزٍ لا عمرُ جلسة** (قرار المالك 2026-09-26). كان تقصيرُ الشريحة إلى ٣٠
     * يجعل استشارةً موعدها قبل ٥٠ دقيقة «فائتة» — فالمسافة بين موعدين كانت تقرّر متى يغيب الموكّل.
     * الفوات الآن مهلةٌ من البداية لها إعدادها (`session_missed_after_minutes`).
     */
    public function test_the_slot_length_does_not_decide_when_a_session_is_missed(): void
    {
        $consult = $this->consult(User::factory()->create(['role' => Role::Client]), [
            'starts_at' => now()->subMinutes(50),
        ]);

        $this->assertFalse($consult->isMissed(), 'موعدها قبل ٥٠ دقيقة ومهلة الفوات ٦٠ — لم تفُت بعد');

        Setting::put('consult_slot_minutes', 30);
        $this->assertFalse($consult->fresh()->isMissed(), 'تقصير مسافة الحجز لا يجعلها فائتة');

        Setting::put('session_missed_after_minutes', 30);
        $this->assertTrue($consult->fresh()->isMissed(), 'مهلة الفوات إعدادُها');
    }

    // ══ إعادة الجدولة ══

    public function test_the_reschedule_limit_is_enforced_from_the_setting(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = $this->consult(User::factory()->create(['role' => Role::Client]));
        $consult->forceFill(['reschedule_count' => 1])->saveQuietly();

        $this->assertNull((new RescheduleConsult)->deny($consult, $lawyer), 'بالافتراض (٢) الإعادة الثانية للطاقم');

        Setting::put('consult_reschedule_limit', 1);
        $denied = (new RescheduleConsult)->deny($consult, $lawyer);
        $this->assertNotNull($denied);
        $this->assertStringContainsString('مرّة واحدة', $denied, 'الجملة تُبنى من القيمة');

        // والإدارة العليا فوق السقف دائماً
        $this->assertNull((new RescheduleConsult)->deny($consult, User::factory()->create(['role' => Role::Admin])));
    }

    public function test_the_client_notice_window_is_read_from_the_setting(): void
    {
        $consult = $this->consult(User::factory()->create(['role' => Role::Client]), [
            'starts_at' => now()->addHours(5), 'day' => now()->format('Y-m-d'),
        ]);

        // الافتراض يوم (1440 دقيقة) — والنصّ بوحدته الطبيعيّة لا بدقائقه
        $this->assertStringContainsString('أقلّ من يوم واحد', (string) $consult->rescheduleRequestBlocker());

        Setting::put('consult_reschedule_notice_minutes', 180);
        $this->assertNull($consult->fresh()->rescheduleRequestBlocker(), 'موعدٌ بعد خمس ساعات ومهلةٌ ثلاث — يُطلب تغييره');

        Setting::put('consult_reschedule_notice_minutes', 360);
        $this->assertStringContainsString('أقلّ من 6 ساعات', (string) $consult->fresh()->rescheduleRequestBlocker());

        // والمهلة تُضبط بالدقائق — ونصفُ الساعة «ونصف» لا «و30 دقيقة»
        Setting::put('consult_reschedule_notice_minutes', 330);
        $this->assertStringContainsString('أقلّ من 5 ساعات ونصف', (string) $consult->fresh()->rescheduleRequestBlocker());
    }

    // ══ الخاصّيّة المشتركة ══

    public function test_screens_receive_the_values_from_the_server(): void
    {
        Setting::put('consult_slot_minutes', 30);
        Setting::put('consult_request_late_minutes', 90);
        Setting::put('consult_reschedule_limit', 4);

        $this->actingAs(User::factory()->create(['role' => Role::Admin]))->get(route('admin.settings'))
            ->assertInertia(fn ($page) => $page
                ->where('settings.consult_slot_minutes', 30)
                ->where('settings.consult_day_start', LawyerAvailability::WORK_START)
                ->where('settings.consult_day_end', LawyerAvailability::WORK_END)
                ->where('settings.consult_request_late_minutes', 90)
                ->where('reschedule.limit', 4)
            );
    }

    // ══ عنوان المكتب وبريده ══

    public function test_office_address_and_email_come_from_settings(): void
    {
        Setting::put('office_address', 'جدة — حي الروضة');
        Setting::put('office_email', 'calendar@office.test');

        $consult = $this->consult(User::factory()->create(['role' => Role::Client]), ['channel' => 'حضورية']);
        $this->assertSame('جدة — حي الروضة', $consult->placeLabel());

        $ics = IcalendarService::generate('T-1', 'استشارة', 'وصف', now()->addDay(), 60, 'https://x.test');
        $this->assertStringContainsString('mailto:calendar@office.test', $ics);
    }

    // ══ الحارس ══

    /**
     * النسخ المنقوشة لا تعود. كلّ نمطٍ مع عدد المرّات المسموح بها لكلّ ملفّ وسببها — والعدد لا
     * الملفّ وحده: سماحُ ملفٍّ كاملٍ يُمرّر نسخةً جديدة تُضاف إليه بصمت.
     */
    public function test_the_replaced_literals_do_not_return(): void
    {
        $rules = [
            // مهلة الفاتورة: التاريخ ونصّه يُبنيان في `InvoiceDue` وحده
            'نصّ مهلة فاتورة منقوش' => ['/[\'"]due_label[\'"]\s*=>\s*[\'"]خلال/u', ['app/Support/Finance/InvoiceDue.php' => 1]],
            'تاريخ استحقاق منقوش' => ['/[\'"]due_at[\'"]\s*=>\s*now\(\)->addDays\(/', ['app/Support/Finance/InvoiceDue.php' => 1]],
            // العنوان والمدينة: السجلّ وحده يقرأ ملفّ الإعداد (افتراضاً)
            'قراءة config(office.*) مباشرة' => ['/config\(\s*[\'"]office\./', ['app/Support/SettingsRegistry.php' => 2]],
            'بريد المنظِّم منقوش' => ['/no-reply@salasel\.sa/', ['app/Support/SettingsRegistry.php' => 1]],
            // الثوابت افتراضاتٌ للسجلّ لا قيمٌ تُقرأ في المنطق
            'قراءة الثابت بدل الإعداد' => ['/RescheduleConsult::LIMIT|RESCHEDULE_REQUEST_NOTICE_MINUTES|\bSLOT_MIN\b|\bWORK_(?:START|END)\b/', [
                'app/Support/SettingsRegistry.php' => 5,   // الافتراضات الخمسة
                'app/Models/Consult.php' => 1,             // إعلان الثابت
                'app/Support/LawyerAvailability.php' => 5, // إعلان الثلاثة + احتياط `workHours` للقيمة الفاسدة
            ]],
            // الواجهة: الحدّ والشبكة ونصّ المدّة من الخاصّيّة المشتركة
            'نسخ الواجهة المنقوشة' => ['/LATE_AFTER_MINS|DAY_HOURS|\(60 دقيقة\)|>\s*ساعتين/u', []],
        ];

        $violations = [];
        foreach ($rules as $label => [$pattern, $allow]) {
            foreach (SettingsNotHardcodedTest::scan($pattern) as $path => $count) {
                if ($count > ($allow[$path] ?? 0)) {
                    $violations[] = "«{$label}» في {$path}: {$count} (المسموح ".($allow[$path] ?? 0).')';
                }
            }
        }

        // الاحتياط المنقوش لمدّة الاستشارة/الموعد (٤٥ و٦٠) — الاجتماعات ودعواتها لها مدّتها فلا تُمسح
        foreach (['app/Models/Consult.php', 'app/Models/Appointment.php', 'app/Services/IcalendarService.php', 'app/Support/Booking/BookingMoved.php'] as $path) {
            if (preg_match('/duration_min\s*\?:\s*\d+/', (string) file_get_contents(base_path($path)))) {
                $violations[] = "احتياط مدّةٍ منقوش في {$path} — الرقم الاسميّ من SessionWindow::nominalMinutes()";
            }
        }

        // والحارس يقرأ السقف من `limit()` لا من ثابته (`self::LIMIT` اسمٌ شائع في أصنافٍ أخرى، فالفحص هنا وحده)
        if (str_contains((string) file_get_contents(base_path('app/Domain/Journey/Transitions/Consult/RescheduleConsult.php')), 'self::LIMIT')) {
            $violations[] = 'RescheduleConsult يقرأ self::LIMIT — استعمل self::limit()';
        }

        $this->assertSame([], $violations, "نسخٌ منقوشة من إعداداتٍ تضبطها الإدارة:\n".implode("\n", $violations));
    }
}
