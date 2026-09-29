<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PayType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\LegalDepartment;
use App\Models\StaffDepartment;
use App\Models\User;
use App\Support\Audit;
use App\Support\LawyerSpecialties;
use App\Support\LegalCatalogue;
use App\Support\Permissions;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/**
 * تسجيل الموظفين وإدارتهم (يطابق adStaff + addStaff + toggleStaff + previewStaff).
 * الموظف = User حقيقي بحساب دخول، دوره enum مشتق من الصفة، وصلاحياته spatie.
 */
class StaffController extends Controller
{
    public function index(Request $request): Response
    {
        $staff = User::query()
            ->whereIn('role', [Role::Employee, Role::Lawyer, Role::Admin])
            ->with('specialties')
            ->orderByDesc('id')->get()
            ->map(fn (User $u) => $u->staffCard());

        return Inertia::render('admin/staff', [
            'staff' => $staff,
            // تخصّصات المحامي من كتالوج الأقسام القانونيّة، وقسم الموظّف من الأقسام الإداريّة —
            // قائمتان منفصلتان (قرار المالك 2026-09-14) بدل قائمةٍ ثابتة تخلطهما
            'legalDepartments' => LegalCatalogue::departments()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'staffDepartments' => StaffDepartment::active()->orderBy('sort_order')->pluck('name'),
            // أنواع الأجر من `PayType` — النموذج يعرض ما يُتاح للدور، والخادم يرفض غيره
            'payTypes' => array_map(fn (PayType $t) => [
                'id' => $t->value,
                'label' => $t->label(),
                'lawyerOnly' => ! $t->allowedFor(Role::Employee),
            ], PayType::cases()),
        ]);
    }

    // تسجيل موظف جديد (يطابق addStaff) — ينشئ User حقيقياً بكلمة مرور عشوائية تُعرض مرة
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), $this->messages());

        $plainPassword = Str::password(14);

        $user = User::create($this->attributes($data) + [
            'password' => Hash::make($plainPassword),
            'avatar_initials' => self::initials($data['name']),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->syncPermissions(Permission::whereIn('name', $this->permsForRole($data['role'], $data['perms'] ?? []))->get());
        $this->syncSpecialties($request->user(), $user, $data);

        // تُعرض كلمة المرور المولّدة للإدارة مرة واحدة (flash) لتسليمها للموظف
        return back()
            ->with('success', 'تم تسجيل الموظف')
            ->with('generatedPassword', ['email' => $user->email, 'password' => $plainPassword]);
    }

    // تعديل موظف قائم (الدور/الصلاحيات/القسم/الأجر/التواصل) — لا يمسّ كلمة المرور
    public function update(Request $request, User $user): RedirectResponse
    {
        // يُعدَّل الموظفون فقط (لا عملاء)
        abort_if($user->role === Role::Client, 403);

        $data = $request->validate($this->rules($user), $this->messages());

        $user->update($this->attributes($data) + ['avatar_initials' => self::initials($data['name'])]);
        $user->syncPermissions(Permission::whereIn('name', $this->permsForRole($data['role'], $data['perms'] ?? []))->get());
        $this->syncSpecialties($request->user(), $user, $data);

        return back()->with('success', 'تم تحديث بيانات الموظف');
    }

    /**
     * تخصّصات المحامي بعد الحفظ: تُكتب له ويُسجَّل تغيّرها؛ ومن لم يعد محامياً تُفرَغ تخصّصاته
     * كي لا يبقى في مسبح الإسناد بقسمٍ قديم.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncSpecialties(User $actor, User $user, array $data): void
    {
        if ($data['role'] !== Role::Lawyer->value) {
            LawyerSpecialties::clear($user);

            return;
        }

        $change = LawyerSpecialties::sync($user, $data['specialties'] ?? [], (bool) ($data['coversAll'] ?? false));

        if ($change['before'] !== $change['after']) {
            Audit::log(
                action: 'تعديل تخصّصات محامٍ',
                description: "عدّل {$actor->name} تخصّصات {$user->name}: {$change['after']}.",
                category: 'موظفون وصلاحيات',
                auditable: $user,
                auditableRef: $user->email,
                beforeState: ['التخصّصات' => $change['before']],
                afterState: ['التخصّصات' => $change['after']],
                user: $actor,
            );
        }
    }

    // يقصر الصلاحيات على المسموح لدور الموظف (دفاع خادمي حتى لو تلاعب أحد بالطلب)
    private function permsForRole(string $role, array $perms): array
    {
        $allowed = Permissions::ROLE_PERMISSIONS[$role] ?? [];
        if ($allowed === 'ALL') {
            return $perms;
        }

        return array_values(array_intersect($perms, $allowed));
    }

    // قواعد التحقّق المشتركة — الهُويّة/الجوال إلزاميّان (للدخول بالـOTP) وفريدان **ضمن الدور**
    // (فيُسمح بإضافة دور آخر لنفس الشخص، ويُمنع تكرار نفس الدور بنفس الهُويّة/الجوال).
    private function rules(?User $ignore = null): array
    {
        $role = (string) request('role');

        return [
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', 'string', 'in:employee,lawyer,admin'], // الدور/اللوحة صراحةً
            'job_title' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($ignore?->id)],
            'mobile' => ['required', Phone::RULE, Rule::unique('users', 'phone')->where('role', $role)->ignore($ignore?->id)],
            'nid' => ['required', 'regex:/^\d{10}$/', Rule::unique('users', 'national_id')->where('role', $role)->ignore($ignore?->id)],
            // قسم الموظّف/الإداريّ من الأقسام الإداريّة (أو قيمته الحاليّة كي يبقى القديم قابلاً للتعديل)؛
            // المحامي لا قسم إداريّاً له — تخصّصاته أدناه
            'dept' => array_merge(['nullable', 'string', 'max:120'], $role === 'lawyer' ? [] : [Rule::in($this->staffDepartmentNames($ignore))]),
            // المحامي: تخصّصاتٌ متعدّدة من كتالوج الأقسام الفعّال، أو «يغطّي كلّ الأقسام»
            'specialties' => ['nullable', 'array'],
            'specialties.*' => ['integer', Rule::exists('legal_departments', 'id')->where('status', LegalDepartment::STATUS_ACTIVE)],
            'coversAll' => ['nullable', 'boolean'],
            'join' => ['nullable', 'date'],
            'start' => ['nullable', 'string', 'max:8'],
            'end' => ['nullable', 'string', 'max:8'],
            // النسبة والجلسة للمحامي وحده (قرار المالك 2026-09-28) — لا يُحفظ أجرٌ لا مصدر له يُحسب منه
            'payType' => ['required', 'string', Rule::in(PayType::values()), function (string $attr, mixed $value, \Closure $fail) use ($role) {
                $type = PayType::tryFrom((string) $value);
                if ($type !== null && ($r = Role::tryFrom((string) $role)) !== null && ! $type->allowedFor($r)) {
                    $fail('النسبة من الأتعاب والأجر بالجلسة للمحامي وحده — الموظّف براتبٍ شهريّ.');
                }
            }],
            'salary' => ['nullable', 'integer', 'min:0'],
            'pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'session' => ['nullable', 'integer', 'min:0'],
            'perms' => ['array'],
            'perms.*' => ['string', Rule::in(Permissions::all())],
        ];
    }

    // رسائل التحقّق العربيّة (مع حارس «حساب واحد لكل دور»)
    private function messages(): array
    {
        return [
            'nid.required' => 'رقم الهوية إلزاميّ (للدخول بالرمز).',
            'nid.regex' => 'رقم الهوية يجب أن يتكوّن من 10 أرقام.',
            'nid.unique' => 'يوجد حساب بهذا الدور لنفس الهوية. اختر دوراً مختلفاً لإضافة حساب آخر لهذا الشخص.',
            'mobile.required' => 'رقم الجوال إلزاميّ (لاستقبال الرمز).',
            'mobile.regex' => 'رقم الجوال غير صالح — محليّ 05XXXXXXXX أو دوليّ ‎+9665XXXXXXXX.',
            'mobile.unique' => 'يوجد حساب بهذا الدور لنفس الجوال. اختر دوراً مختلفاً.',
            'email.unique' => 'البريد الإلكتروني مستخدم في حساب آخر (لكل حساب بريد مختلف).',
            'dept.in' => 'اختر القسم الإداريّ من القائمة.',
            'specialties.*.exists' => 'أحد التخصّصات المختارة غير متاح حالياً، يُرجى اختيار تخصّصٍ من القائمة.',
        ];
    }

    /**
     * الأقسام الإداريّة المقبولة للموظّف: الفعّالة، وقيمته الحاليّة إن كانت قديمةً خارجها —
     * فتعديل موظّفٍ قديم لا يُجبر الإدارة على تغيير قسمه في الحفظ نفسه.
     *
     * @return list<string>
     */
    private function staffDepartmentNames(?User $ignore): array
    {
        $names = StaffDepartment::active()->orderBy('sort_order')->pluck('name')->all();

        if (filled($ignore?->department) && ! $ignore->isLawyer()) {
            $names[] = $ignore->department;
        }

        return array_values(array_unique($names));
    }

    // بحث عن شخص بالهُويّة — لتلميح «إضافة دور آخر» في نموذج الموظف (الإدارة)
    public function lookup(Request $request): JsonResponse
    {
        $nid = (string) $request->query('nid', '');

        if (! preg_match('/^\d{10}$/', $nid)) {
            return response()->json(['exists' => false]);
        }

        $users = User::where('national_id', $nid)->get();

        if ($users->isEmpty()) {
            return response()->json(['exists' => false]);
        }

        return response()->json([
            'exists' => true,
            'name' => $users->first()->name,
            'phone' => (string) $users->first()->phone, // صريحٌ للإدارة العليا (قرار المالك 2026-09-11: لا تقنيع في لوحتها)
            'roles' => $users->map(fn (User $u) => $u->role->label())->unique()->values(),
        ]);
    }

    // تحويل المُدخلات المتحقّقة إلى أعمدة الموديل (مشترك بين الإنشاء والتعديل)
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => Role::from($data['role']),
            'job_title' => $data['job_title'],
            'phone' => $data['mobile'] ?? null,
            'national_id' => $data['nid'] ?? null,
            'join_date' => $data['join'] ?? null,
            'work_start' => $data['start'] ?? null,
            'work_end' => $data['end'] ?? null,
            'pay_type' => ($pay = PayType::from($data['payType']))->value,
            'salary' => $pay->hasSalary() ? ($data['salary'] ?? 0) : 0,
            'pay_pct' => $pay->hasPercent() ? ($data['pct'] ?? null) : null,
            'session_fee' => $pay->isSession() ? ($data['session'] ?? null) : null,
        ]
            // قسم المحامي تكتبه مزامنة تخصّصاته (syncSpecialties)، ولغيره قسمه الإداريّ
            + ($data['role'] === Role::Lawyer->value ? [] : ['department' => $data['dept'] ?? null]);
    }

    // تفعيل/إيقاف الموظف (يطابق toggleStaff) — للموظفين فقط (لا عملاء ولا إدارة)
    public function toggle(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isAdmin(), 403);
        abort_if($user->role === Role::Client, 403);
        $user->setSuspended($user->isActive());

        return back()->with('success', $user->isActive() ? 'تم تفعيل الموظف' : 'تم إيقاف الموظف');
    }

    // أول حرفَي كلمتين (بعد تنظيف «أ.») — يطابق توليد avatar في staff.tsx
    private static function initials(string $name): string
    {
        $clean = preg_replace('/^أ\.?\s*/u', '', trim($name));
        $parts = preg_split('/\s+/u', $clean);

        return mb_substr($parts[0] ?? '', 0, 1).(isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1) : '');
    }
}
