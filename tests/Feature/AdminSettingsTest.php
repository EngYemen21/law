<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\Setting;
use App\Models\User;
use App\Support\CaseFee;
use App\Support\ExecFee;
use App\Support\ExecFlow;
use App\Support\ExecService;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تبويب «إعدادات النظام» — متغيّرات كانت ثوابتَ في الشيفرة أو صفوفاً بلا شاشة.
 *
 * أهمّ ما يحرسه هذا الملفّ ليس الشاشة بل **تطابق الافتراض في السجلّ مع الثابت في الشيفرة**:
 * الثابت يبقى مُعلَناً ليُقرأ في موضعه، والسجلّ ينسخه افتراضاً — ونسختان بلا حارسٍ تتباعدان
 * بصمت، فيقرأ نصف النظام رقماً ونصفه الآخر رقماً سواه.
 */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    /** ملفّ تنفيذٍ قُيّد لدى المحكمة — جاهزٌ للإبلاغ بأمر التنفيذ (منه تُحسب مهلة الوفاء). */
    private function registeredExecution(User $client, User $lawyer): Execution
    {
        return Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-S-'.uniqid(),
            'subject' => 'تنفيذ حكم مالي',
            'sanad' => 'حكم قضائي',
            'amount' => 90000,
            'stage' => 8,
            'status' => ExecFlow::label(8),
            'tone' => ExecFlow::tone(8),
            'paid' => true,
            'exec_no' => 'EXE-SET-'.uniqid(),
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
            'registered_at' => now()->subDays(3)->toDateString(),
        ]);
    }

    // ── ١ ──
    public function test_admin_sees_the_settings_tab_with_the_registry_behind_it(): void
    {
        $this->actingAs($this->admin())->get(route('admin.settings'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/settings')
                ->has('fields')
                ->has('groups')
                ->has('values')
                ->where('values.exec_pay_days', ExecFlow::PAY_DAYS)
            );
    }

    // ── ٢ ──
    public function test_non_admin_never_reaches_the_settings_tab(): void
    {
        foreach ([Role::Client, Role::Employee, Role::Lawyer] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('admin.settings'))->assertRedirect();
            $this->actingAs($user)->post(route('admin.settings.update'), ['exec_pay_days' => 9])->assertRedirect();
        }

        // ولا كتابةَ وقعت من المحاولات الثلاث
        $this->assertNull(Setting::get('exec_pay_days'));
    }

    // ── ٣ ──
    public function test_saving_writes_the_value_and_audits_the_difference_alone(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.settings.update'), [
            'exec_pay_days' => 7,
            'exec_max_collection_pct' => (int) ExecFee::MAX_PCT, // لم يتغيّر
        ])->assertRedirect()->assertSessionHas('flash');

        $this->assertSame('7', Setting::get('exec_pay_days'));
        $this->assertSame(7, SettingsRegistry::int('exec_pay_days'));

        $log = AuditLog::where('action', 'تعديل إعدادات النظام')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('warning', $log->severity);
        // الفرق وحده: المفتاح الذي لم يتغيّر لا يدخل القيد، ولا الثمانية الباقية
        $this->assertSame(['exec_pay_days' => 7], $log->after_state);
        $this->assertSame(['exec_pay_days' => ExecFlow::PAY_DAYS], $log->before_state);
    }

    // ── ٤ ──
    public function test_a_value_out_of_range_is_refused_and_nothing_is_written(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.update'), ['exec_pay_days' => 99])
            ->assertSessionHasErrors('exec_pay_days');

        $this->assertNull(Setting::get('exec_pay_days'));
        $this->assertSame(ExecFlow::PAY_DAYS, SettingsRegistry::int('exec_pay_days'));
    }

    // ── ٥ — الحارس الأهمّ: الافتراض في السجلّ هو الثابت في الشيفرة ──
    public function test_registry_defaults_match_the_constants_they_replace(): void
    {
        $fields = SettingsRegistry::all();

        $this->assertSame(ExecFlow::PAY_DAYS, $fields['exec_pay_days']['default']);
        $this->assertSame(ExecFee::MAX_PCT, (float) $fields['exec_max_collection_pct']['default']);
        $this->assertSame(CaseFee::INSTALLMENTS, $fields['installments_count']['default']);
        $this->assertSame(ExecFee::INSTALLMENTS, $fields['installments_count']['default']);
        // **الافتراض هو النصّ المطبوع قبل هذا التغيير، لا `APP_NAME`.** الاثنان يختلفان
        // بحرف: الكود يكتبها «المحاماة» و`APP_NAME` في البيئة «المحاماه» — ومقياسُ الصواب
        // هنا ألّا يتبدّل ما يقرؤه العميل على رأس كلّ مستند، لا أن يطابق السجلُّ البيئةَ.
        $this->assertSame('النظام الإداري لمكاتب المحاماة', $fields['office_name']['default']);
        $this->assertNotSame((string) config('app.name'), $fields['office_name']['default'], 'الافتراض ليس APP_NAME — بينهما فرق حرف');

        // ولا مفتاح أسرارٍ في السجلّ — الشاشة لا تعرض إلّا ما فيه
        foreach (array_keys($fields) as $key) {
            $this->assertDoesNotMatchRegularExpression('/key|secret|token|password/i', $key);
        }
    }

    // ── ٦ ──
    public function test_a_longer_pay_window_binds_new_files_only(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $notified = now()->subDay()->startOfDay();

        $old = $this->registeredExecution($client, $lawyer);
        ExecService::notifyDebtor($old, $notified->toDateString(), $lawyer);
        $oldDue = $old->fresh()->pay_due_at?->toDateString();
        $this->assertSame($notified->copy()->addDays(ExecFlow::PAY_DAYS)->toDateString(), $oldDue);

        $this->actingAs($this->admin())
            ->post(route('admin.settings.update'), ['exec_pay_days' => 7])->assertRedirect();

        $new = $this->registeredExecution($client, $lawyer);
        ExecService::notifyDebtor($new, $notified->toDateString(), $lawyer);

        $this->assertSame($notified->copy()->addDays(7)->toDateString(), $new->fresh()->pay_due_at?->toDateString());
        $this->assertSame($oldDue, $old->fresh()->pay_due_at?->toDateString(), 'مهلة ملفٍّ أُبلغ قبل التغيير لا تتزحزح');
    }

    // ── ٧ ──
    public function test_an_unwritten_setting_reads_its_declared_default(): void
    {
        $this->assertSame(0, Setting::query()->count());

        $this->assertSame(ExecFlow::PAY_DAYS, SettingsRegistry::int('exec_pay_days'));
        $this->assertSame('2026-10-28', SettingsRegistry::date('exec_working_days_from'));
        $this->assertSame('011 462 2277', SettingsRegistry::str('office_phone'));

        // وقيمةٌ فاسدة في القاعدة تُقيَّد بمداها بدل أن توقف ميزة
        Setting::put('installments_count', '900');
        $this->assertSame(6, SettingsRegistry::int('installments_count'));
    }

    // ── ٨ ──
    public function test_a_key_outside_the_registry_is_ignored(): void
    {
        $this->actingAs($this->admin())->post(route('admin.settings.update'), [
            'office_phone' => '011 000 1111',
            'gemini_api_key' => 'sk-should-never-be-written',
            'vat_rate' => 99,
        ])->assertRedirect();

        $this->assertSame('011 000 1111', Setting::get('office_phone'));
        $this->assertNull(Setting::get('gemini_api_key'));
        $this->assertNull(Setting::get('vat_rate'), 'مفتاح شاشة الأسعار لا يُكتب من هنا');
    }
}
