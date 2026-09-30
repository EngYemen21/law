<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * **المدير الأوّل** (قرار المالك 2026-09-30) — بياناته في موضعٍ واحد يقرؤه أمر التثبيت `admin:first` (للإنتاج)
 * و`DatabaseSeeder` (لصندوق التجربة). في الإنتاج لا تُنشئ البذرة حسابات (فصل البيئات)، فتثبيتٌ من الصفر كان بلا مدير.
 */
final class FirstAdmin
{
    /** @var array{name: string, email: string, national_id: string, phone: string, avatar_initials: string, job_title: string} */
    public const ATTRIBUTES = [
        'name' => 'الإدارة العليا',
        'email' => 'kfykfy2020@gmail.com',
        'national_id' => '1000000001',
        'phone' => '+966537434000',
        'avatar_initials' => 'إ ع',
        'job_title' => 'مدير عام',
    ];

    /** سبب رفض الإنشاء، أو `null` إن جاز — مديرٌ قائم أو هويّةٌ/بريدٌ مستعملان يمنعانه (لا يُكتب فوق حسابٍ أبداً). */
    public static function blocker(string $nationalId, string $email): ?string
    {
        if (User::where('role', Role::Admin)->exists()) {
            return 'يوجد مديرٌ في النظام — يُنشأ الطاقم من «فريق العمل»، لا من هذا الأمر.';
        }

        if (User::where('national_id', $nationalId)->orWhere('email', $email)->exists()) {
            return 'رقم الهويّة أو البريد مستعملٌ لحسابٍ قائم — لا يُكتب فوقه.';
        }

        return null;
    }

    /**
     * ينشئ المدير الأوّل نشطاً بكلمة مرورٍ عشوائيّة لا تُستعمل (الدخول بالهويّة ورمز الجوال كالتسجيل).
     *
     * @param  array{name?: string, email?: string, national_id?: string, phone?: string}  $overrides
     */
    public static function create(array $overrides = []): User
    {
        return User::create(array_merge(self::ATTRIBUTES, array_filter($overrides), [
            'role' => Role::Admin,
            'password' => Hash::make(Str::password(32)),
            'status' => 'active',
            'email_verified_at' => now(),
        ]));
    }
}
