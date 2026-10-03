<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * **حساب العميل — قواعده وإنشاؤه من مصدرٍ واحد.**
 *
 * يُنشأ الحساب من طريقين: تسجيل العميل بنفسه (`AuthController`) والإدارة من صفحة العملاء
 * (`Admin\ClientController::store`). القواعد هنا واحدة للطريقين، فلا يقبل أحدهما ما يرفضه الآخر:
 * اسمٌ من كلمتين، هويّةٌ من عشرة أرقام، جوالٌ وبريدٌ لا يتكرّران — والجوال يُقارَن بصيغه كلّها
 * (`Phone::variants`)، و`0555…` و`966555…` رقمٌ واحد.
 *
 * والدخول بالهويّة ثمّ رمزٍ إلى الجوال المسجَّل — فلا كلمة مرور تُسلَّم، والعمود يُملأ بقيمةٍ عشوائيّة.
 */
final class ClientAccount
{
    public const AUDIT_CATEGORY = 'العملاء';

    /**
     * @param  string  $phoneTaken  رسالة الجوال المكرَّر — العميل يُدعى إلى الدخول، والإدارة تُخبَر بوجوده
     * @return array<string, list<mixed>>
     */
    public static function rules(string $phoneTaken = 'يوجد حساب عميل مسجّل بهذا الجوال.'): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^\S+\s+\S+/u'],
            // التفرّد ضمن دور العميل (يسمح بأن يكون للشخص حساب موظف/محامٍ بنفس الهُويّة)
            'national_id' => ['required', 'regex:/^\d{10}$/', Rule::unique('users', 'national_id')->where('role', Role::Client->value)],
            'phone' => ['required', Phone::RULE, self::uniqueClientPhone($phoneTaken)],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'name.required' => 'أدخل الاسم الكامل.',
            'name.regex' => 'أدخل الاسم كاملاً (كلمتان على الأقل).',
            'name.max' => 'الاسم طويل جداً.',
            'national_id.required' => 'أدخل رقم الهوية.',
            'national_id.regex' => 'رقم الهوية يجب أن يتكوّن من 10 أرقام.',
            'national_id.unique' => 'يوجد حساب عميل مسجّل بهذه الهوية.',
            'phone.required' => 'أدخل رقم الجوال.',
            'phone.regex' => 'رقم الجوال غير صالح — محليّ 05XXXXXXXX أو دوليّ ‎+9665XXXXXXXX.',
            'email.required' => 'أدخل البريد الإلكتروني.',
            'email.email' => 'أدخل بريداً إلكترونياً صحيحاً.',
            'email.max' => 'البريد الإلكتروني طويل جداً.',
            'email.unique' => 'يوجد حساب مسجّل بهذا البريد الإلكتروني.',
        ];
    }

    /**
     * **هويّةٌ أو جوالٌ يخصّان أحداً من الطاقم؟** — حساب العميل لا يُنشأ عليهما، فيسدّ انتحال هويّة الطاقم
     * (حسابات الطاقم تُضاف من شاشة الموظّفين وحدها).
     */
    public static function belongsToStaff(string $nationalId, string $phone): bool
    {
        return User::whereIn('role', [Role::Employee->value, Role::Lawyer->value, Role::Admin->value])
            ->where(fn ($q) => $q->where('national_id', $nationalId)->orWhereIn('phone', Phone::variants($phone)))
            ->exists();
    }

    /**
     * ينشئ حساب العميل نشطاً. `$verified`: أكّد العميل جواله وبريده برمزٍ (التسجيل الذاتيّ) — وما تُنشئه
     * الإدارة يبقى غير مؤكَّد حتى يدخل صاحبه.
     *
     * @param  array{name: string, national_id: string, phone: string, email: string}  $data
     */
    public static function create(array $data, bool $verified): User
    {
        return User::create([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'national_id' => $data['national_id'],
            'phone' => trim($data['phone']),
            'role' => Role::Client,
            'status' => 'active',
            'avatar_initials' => self::initials($data['name']),
            'phone_verified_at' => $verified ? now() : null,
            'email_verified_at' => $verified ? now() : null,
            // كلمة مرور عشوائيّة مُجزّأة لتلبية العمود — الدخول بالـOTP لا بها
            'password' => Hash::make(Str::password(32)),
        ]);
    }

    /** الأحرف الأولى من أوّل كلمتين للأفاتار (مثل «ع ع»). */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return mb_substr($parts[0] ?? '', 0, 1).' '.mb_substr($parts[1] ?? '', 0, 1);
    }

    /** جوالٌ لا يحمله حساب عميلٍ آخر بأيّ صيغة. */
    private static function uniqueClientPhone(string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            $taken = User::where('role', Role::Client->value)
                ->whereIn('phone', Phone::variants((string) $value))
                ->exists();

            if ($taken) {
                $fail($message);
            }
        };
    }
}
