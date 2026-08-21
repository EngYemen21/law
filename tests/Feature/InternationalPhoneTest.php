<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الأرقام الدولية (الدفعة هـ): الدخول برمز OTP على الجوال حصراً، وقيد ^05\d{8}$
 * كان يحجب كل موكّل غير سعوديّ عن المنصّة كلّها لا عن ميزة واحدة.
 */
class InternationalPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_accepts_saudi_and_international_numbers(): void
    {
        $rule = str_replace('regex:', '', Phone::RULE);

        foreach (['0551234567', '+967779475324', '967779475324', '+966501234567'] as $ok) {
            $this->assertSame(1, preg_match($rule, $ok), "يجب قبول {$ok}");
        }

        foreach (['0512345', 'abc', '05512345678901234', ''] as $bad) {
            $this->assertSame(0, preg_match($rule, $bad), "يجب رفض {$bad}");
        }
    }

    public function test_intl_normalizes_local_and_keeps_international(): void
    {
        $this->assertSame('966551234567', Phone::intl('0551234567'));
        $this->assertSame('966551234567', Phone::intl('+966551234567'));
        $this->assertSame('966551234567', Phone::intl('551234567'));
        $this->assertSame('967779475324', Phone::intl('+967779475324'));
        $this->assertSame('967779475324', Phone::intl('00967779475324'));
    }

    public function test_sendable_rejects_truncated_numbers(): void
    {
        $this->assertTrue(Phone::isSendable('0551234567'));
        $this->assertTrue(Phone::isSendable('+967779475324'));
        $this->assertFalse(Phone::isSendable('12345'));
    }

    public function test_admin_can_register_staff_with_international_number(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'موظف دولي',
            'role' => 'employee',
            'job_title' => 'موظف خدمة عملاء',
            'email' => 'intl@salasel.test',
            'mobile' => '+967779475324',
            'nid' => '1234567890',
            'payType' => 'salary',
            'salary' => 5000,
            'perms' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'intl@salasel.test']);
    }
}
