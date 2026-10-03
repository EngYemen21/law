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
        // «إغلاق الاجتماع» لا يسبق «عدّ الجلسة فائتة» (ساعة افتراضاً) — يُخفَض ذاك أوّلاً
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

    /** افتراضات المالك (2026-10-02، والفوات ساعة 2026-10-03) — ومتّسقةٌ مع قواعد الترتيب بلا ضبطٍ من الإدارة. */
    public function test_the_owner_defaults_hold_together(): void
    {
        $this->assertSame([
            'consult_slot_minutes' => 60, 'consult_reschedule_notice_minutes' => 720, 'ticket_escalate_minutes' => 30,
            'session_missed_after_minutes' => 60, 'consult_autoclose_minutes' => 60, 'meeting_autoclose_minutes' => 60,
            'session_stale_minutes' => 360, 'meeting_reminder_lead' => 720, 'meeting_reminder_near_minutes' => 10,
            'session_join_opens_minutes' => 5, 'consult_staff_start_minutes' => 5, 'consult_reminder_far_minutes' => 720,
            'consult_reminder_near_minutes' => 30, 'meet_invite_expire_minutes' => 0, 'consult_request_late_minutes' => 5,
        ], array_map(fn (string $key) => SettingsRegistry::int($key), array_combine($keys = [
            'consult_slot_minutes', 'consult_reschedule_notice_minutes', 'ticket_escalate_minutes',
            'session_missed_after_minutes', 'consult_autoclose_minutes', 'meeting_autoclose_minutes',
            'session_stale_minutes', 'meeting_reminder_lead', 'meeting_reminder_near_minutes',
            'session_join_opens_minutes', 'consult_staff_start_minutes', 'consult_reminder_far_minutes',
            'consult_reminder_near_minutes', 'meet_invite_expire_minutes', 'consult_request_late_minutes',
        ], $keys)));

        $this->assertSame([], SettingsRegistry::relationErrors(SettingsRegistry::values()));
    }

    /** الدعوة غير المعتمدة تنتهي عند موعدها: الصفر هنا معنى («عند الموعد») لا تعطيل. */
    public function test_an_unapproved_invite_expires_at_its_time(): void
    {
        $this->save(['meet_invite_expire_minutes' => 0])->assertSessionHasNoErrors();
        $this->save(['meet_invite_expire_minutes' => -1])->assertSessionHasErrors('meet_invite_expire_minutes');
    }
}
