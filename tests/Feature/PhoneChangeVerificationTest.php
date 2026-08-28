<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الجوال لا يُكتب إلا بعد تأكيد رمز يصل إلى الرقم الجديد.
 *
 * كان يُحفَظ فوراً بلا أي تحقّق — والجوال عامل المصادقة الوحيد (الدخول برمز OTP) وقناة
 * تذكيرات SMS معاً. فمن يجلس أمام جلسة مفتوحة دقيقةً يغيّر الرقم ويستولي على الحساب
 * نهائياً، وصاحبه لا يستطيع حتى تسجيل الدخول ليستعيده.
 */
class PhoneChangeVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PHONE = '0551112233';

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client, 'phone' => '+966500000001']);
    }

    /** الحارس الأساسي: الطلب وحده لا يغيّر شيئاً. */
    public function test_submitting_a_new_phone_does_not_change_it_yet(): void
    {
        $user = $this->client();
        $old = $user->phone;

        $this->actingAs($user)->post(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => self::NEW_PHONE,
        ])->assertRedirect();

        $this->assertSame($old, $user->fresh()->phone, 'الجوال تغيّر بلا تأكيد — استيلاء على الحساب.');
    }

    /** وبقيّة الحقول تُحفظ رغم تعليق الجوال — لا يُعطَّل تحرير الملفّ كلّه. */
    public function test_other_fields_still_save_while_the_phone_waits(): void
    {
        $user = $this->client();

        $this->actingAs($user)->post(route('profile.update'), [
            'name' => 'اسم محدَّث',
            'email' => 'updated@example.test',
            'phone' => self::NEW_PHONE,
        ])->assertRedirect();

        $fresh = $user->fresh();
        $this->assertSame('اسم محدَّث', $fresh->name);
        $this->assertSame('updated@example.test', $fresh->email);
    }

    /** التأكيد بالرمز الصحيح (AUTH_DEV_OTP في بيئة الاختبار) يكتب الرقم. */
    public function test_confirming_the_code_writes_the_new_phone(): void
    {
        config(['services.auth_dev_otp' => '1234']);
        $user = $this->client();

        $this->actingAs($user)->post(route('profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'phone' => self::NEW_PHONE,
        ])->assertRedirect();

        $this->actingAs($user)->post(route('profile.phone.verify'), ['code' => '1234'])->assertRedirect();

        $this->assertSame(Phone::intl(self::NEW_PHONE), $user->fresh()->phone);
    }

    /** رمز خاطئ لا يكتب شيئاً. */
    public function test_a_wrong_code_leaves_the_phone_untouched(): void
    {
        config(['services.auth_dev_otp' => '1234']);
        $user = $this->client();
        $old = $user->phone;

        $this->actingAs($user)->post(route('profile.update'), [
            'name' => $user->name, 'email' => $user->email, 'phone' => self::NEW_PHONE,
        ])->assertRedirect();

        $this->actingAs($user)->post(route('profile.phone.verify'), ['code' => '9999'])
            ->assertSessionHasErrors('code');

        $this->assertSame($old, $user->fresh()->phone);
    }

    /** تأكيد بلا طلب معلّق يُرفض — لا يُكتب رقم من فراغ. */
    public function test_verifying_without_a_pending_request_is_refused(): void
    {
        $user = $this->client();

        $this->actingAs($user)->post(route('profile.phone.verify'), ['code' => '1234'])
            ->assertSessionHasErrors('code');
    }
}
