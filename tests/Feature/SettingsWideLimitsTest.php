<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **حدود الإعدادات تقنيّةٌ لا تشغيليّة** (قرار المالك 2026-10-02).
 *
 * ثبت في الشاشة: «المسافة بين مواعيد الحجز» = 2 تُرفض بـ«15 على الأقلّ»، و«إغلاق الاجتماع الذي لم ينعقد»
 * كذلك — حدودٌ اختياريّة لا يعطّل ما دونها شيئاً. الآن: المدد والأعداد من 1 فما فوق وسقفٌ واسع يصدّ خطأ
 * الكتابة، والصفر مرفوض، وقواعد الترتيب بين الحقول باقية.
 */
class SettingsWideLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function save(array $values)
    {
        return $this->actingAs(User::factory()->create(['role' => Role::Admin]))->post(route('admin.settings.update'), $values);
    }

    public function test_small_durations_the_owner_chooses_are_accepted(): void
    {
        $this->save(['consult_slot_minutes' => 2])->assertSessionHasNoErrors();
        $this->save(['session_missed_after_minutes' => 1, 'meeting_autoclose_minutes' => 1, 'consult_autoclose_minutes' => 1])->assertSessionHasNoErrors();

        SettingsRegistry::flush();
        $this->assertSame(2, SettingsRegistry::int('consult_slot_minutes'));
        $this->assertSame(1, SettingsRegistry::int('meeting_autoclose_minutes'));
    }

    public function test_zero_and_negatives_are_refused(): void
    {
        $this->save(['consult_slot_minutes' => 0, 'meeting_autoclose_minutes' => -5, 'invoice_due_days_case' => 0])
            ->assertSessionHasErrors(['consult_slot_minutes', 'meeting_autoclose_minutes', 'invoice_due_days_case']);
        $this->assertNull(Setting::get('consult_slot_minutes'));
    }

    /** السقف يصدّ خطأ الكتابة — وصلاحيّة رمز الدخول سقفها أمنيّ فيبقى. */
    public function test_the_ceiling_catches_typos_and_the_otp_ceiling_stays(): void
    {
        $this->save(['exec_pay_days' => 999999])->assertSessionHasErrors('exec_pay_days');
        $this->save(['exec_pay_days' => 120])->assertSessionHasNoErrors();
        $this->save(['otp_ttl_minutes' => 6])->assertSessionHasErrors('otp_ttl_minutes');
        $this->save(['otp_ttl_minutes' => 1])->assertSessionHasNoErrors();
    }

    public function test_the_ordering_rules_still_hold(): void
    {
        // «إغلاق الاجتماع» لا يسبق «عدّ الجلسة فائتة» (60 افتراضاً) — يُخفَض ذاك أوّلاً
        $this->save(['meeting_autoclose_minutes' => 1])->assertSessionHasErrors('meeting_autoclose_minutes');
        $this->save(['installment_first_due_days' => 3, 'installment_interval_days' => 2])->assertSessionHasErrors('installment_interval_days');
    }

    public function test_one_installment_is_not_a_plan(): void
    {
        $this->save(['installments_count' => 1])->assertSessionHasErrors('installments_count');
        $this->save(['installments_count' => 12])->assertSessionHasNoErrors();
    }

    /** التحقّق يُشتقّ من `min`/`max` الحقل — فلا يتباعد ما تعرضه الشاشة عمّا يقبله الخادم. */
    public function test_validation_is_derived_from_the_field_bounds(): void
    {
        $rules = SettingsRegistry::rulesFor();

        foreach (SettingsRegistry::all() as $key => $field) {
            if ($field['type'] !== 'int') {
                continue;
            }
            $this->assertContains('min:'.$field['min'], $rules[$key], $key);
            $this->assertContains('max:'.$field['max'], $rules[$key], $key);
            $this->assertGreaterThanOrEqual(0, $field['min'], $key);
        }
    }
}
