<?php

namespace Tests\Feature;

use App\Enums\PayType;
use App\Enums\Role;
use App\Jobs\SendSmsJob;
use App\Mail\HearingReminderMail;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Support\Finance\LawyerShare;
use App\Support\LawyerWorkload;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * **ثوابت صارت إعدادات** (تدقيق الإعدادات — المرحلة ٢، 2026-09-30): كلّ اختبارٍ يغيّر الإعداد ويُثبت أنّ السلوك
 * تبعه — وكانت هذه القيم منقوشةً فلا يغيّرها إلّا نشر كود.
 */
class SettingsConstantsStageTwoTest extends TestCase
{
    use RefreshDatabase;

    private function set(string $key, mixed $value): void
    {
        Setting::put($key, $value);
        SettingsRegistry::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    // ── نصيب المحامي الافتراضيّ ──

    public function test_the_default_lawyer_share_follows_the_setting(): void
    {
        $salaried = User::factory()->create(['role' => Role::Lawyer, 'pay_type' => PayType::Salary->value]);
        $this->assertSame(LawyerShare::DEFAULT_PCT, LawyerShare::defaultPctFor($salaried));

        $this->set('lawyer_default_share_pct', 35);

        $this->assertSame(35, LawyerShare::defaultPctFor($salaried));
        $this->assertSame(35, LawyerShare::defaultPctFor(null));
    }

    // ── عبء المحامي: تعريفٌ واحد وعتباتٌ من الإعدادات ──

    public function test_workload_thresholds_follow_the_settings(): void
    {
        $this->assertSame('moderate', LawyerWorkload::capacity(6));

        $this->set('workload_moderate_from', 10);
        $this->set('workload_busy_from', 20);

        $this->assertSame('available', LawyerWorkload::capacity(6));
        $this->assertSame('moderate', LawyerWorkload::capacity(19));
        $this->assertSame('busy', LawyerWorkload::capacity(20));
    }

    /** كانت اللوحة تحسب الحِمل بأوزانٍ أخرى ولا تعدّ ملفّات التنفيذ — فالمحامي نفسه «متاح» فيها و«متوسّط» في صفحة المحامين. */
    public function test_the_dashboard_reads_the_same_workload_as_the_lawyers_page(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $client = User::factory()->create(['role' => Role::Client]);
        foreach (range(1, 3) as $i) {
            Execution::create([
                'user_id' => $client->id, 'number' => "EXE-LOAD-{$i}", 'subject' => 'تنفيذ حكم', 'status' => 'عرض الخدمة',
                'tone' => 'b-amber', 'stage' => 5, 'amount' => 1000, 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
            ]);
        }

        $expected = LawyerWorkload::forMany([$lawyer->id])[$lawyer->id];
        $this->assertSame('moderate', $expected['capacity'], 'ثلاثة ملفّات تنفيذ × 2 = 6 نقاط');

        $row = collect(app(AdminDashboardService::class)->get360Data(bypassCache: true)['lawyersWorkload'])->firstWhere('id', $lawyer->id);
        $this->assertSame($expected['capacity'], $row['status']);
        $this->assertSame(3, $row['activeExecutions']);
    }

    // ── نافذة الدخول ونافذة بدء الطاقم ──

    public function test_the_join_window_and_its_text_follow_the_setting(): void
    {
        $in8 = now()->addMinutes(8);
        $this->assertFalse(SessionWindow::joinOpened($in8), 'الافتراض خمس دقائق (قرار المالك 2026-10-02)');
        $this->assertStringContainsString('5 دقائق', SessionWindow::refuseNotOpen());

        $this->set('session_join_opens_minutes', 10);

        $this->assertTrue(SessionWindow::joinOpened($in8));
        $this->assertStringContainsString('10 دقائق', SessionWindow::refuseNotOpen());
        $this->assertSame(10, $this->get(route('login'))->viewData('page')['props']['settings']['session_join_opens_minutes']);
    }

    public function test_the_staff_start_window_follows_the_setting(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-START-1', 'subject' => 'نزاع', 'channel' => 'مرئية', 'lawyer' => 'أ. سارة',
            'status' => 'جديدة', 'session' => 'بانتظار الجلسة', 'starts_at' => now()->addMinutes(20), 'when_label' => 'اليوم',
        ]);
        $this->assertFalse($consult->isStartable(), 'قبل الموعد بعشرين دقيقة والنافذة ١٥');

        $this->set('consult_staff_start_minutes', 30);

        $this->assertTrue($consult->fresh()->isStartable());
    }

    // ── طبقات التذكير ──

    public function test_the_consult_text_reminder_lead_follows_the_setting(): void
    {
        Bus::fake();
        config(['services.taqnyat.api_key' => 'tok_test', 'services.taqnyat.sender' => 'Salasel']);
        $client = User::factory()->create(['role' => Role::Client, 'phone' => '+966555550091']);
        $consult = Consult::create([
            // حضوريّة: المرئيّة تذكيرها القريب إشعارٌ ورسالتها عند فتح الدخول (قرار «ب»)
            'user_id' => $client->id, 'ref' => 'CN-REM-45', 'subject' => 'نزاع', 'channel' => 'حضورية', 'lawyer' => 'أ. سارة',
            'status' => 'جديدة', 'session' => 'بانتظار الجلسة', 'starts_at' => now()->addMinutes(45), 'when_label' => 'اليوم',
            'paid_at' => now()->subDay(), 'reminder_24h_sent_at' => now()->subHour(),
        ]);

        $this->artisan('consults:send-reminders')->assertSuccessful();
        Bus::assertNotDispatched(SendSmsJob::class);

        $this->set('consult_reminder_near_minutes', 60);
        $this->artisan('consults:send-reminders')->assertSuccessful();

        Bus::assertDispatched(SendSmsJob::class);
        $this->assertNotNull($consult->fresh()->reminder_30m_sent_at);
    }

    public function test_the_hearing_reminder_layers_follow_the_settings(): void
    {
        Mail::fake();
        $client = User::factory()->create(['role' => Role::Client, 'email' => 'c@example.com']);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-6190', 'type' => 'نزاع تجاري', 'status' => 'منظورة', 'tone' => 'b-blue', 'update_text' => '—',
        ]);
        $hearing = $case->hearings()->create([
            'title' => 'الجلسة الأولى', 'day' => now()->addDays(2)->toDateString(), 'court' => 'الدائرة التجارية', 'status' => 'مجدولة',
            'starts_at' => now()->addHours(30),
        ]);

        $this->artisan('hearings:send-reminders')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->set('hearing_reminder_far_minutes', 2160); // 36 ساعة
        $this->artisan('hearings:send-reminders')->assertSuccessful();

        Mail::assertQueued(HearingReminderMail::class);
        $this->assertNotNull($hearing->fresh()->reminder_24h_sent_at);
    }

    // ── علاقات تمنع قيماً تعطّل بعضها ──

    public function test_relations_between_the_new_settings_are_enforced(): void
    {
        $post = fn (array $data) => $this->actingAs($this->admin())->post(route('admin.settings.update'), $data);

        $post(['session_join_opens_minutes' => 10, 'consult_staff_start_minutes' => 5])->assertSessionHasErrors('consult_staff_start_minutes');
        $post(['session_join_opens_minutes' => 30, 'consult_reminder_near_minutes' => 30])->assertSessionHasErrors('consult_reminder_near_minutes');
        $post(['consult_reminder_near_minutes' => 120, 'consult_reminder_far_minutes' => 120])->assertSessionHasErrors('consult_reminder_far_minutes');
        $post(['hearing_reminder_near_minutes' => 120, 'hearing_reminder_far_minutes' => 60])->assertSessionHasErrors('hearing_reminder_far_minutes');
        $post(['workload_moderate_from' => 10, 'workload_busy_from' => 10])->assertSessionHasErrors('workload_busy_from');

        $this->assertSame(0, Setting::query()->count());
    }
}
