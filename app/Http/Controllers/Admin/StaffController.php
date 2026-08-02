<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
            ->whereIn('role', [Role::Employee, Role::Lawyer])
            ->orderByDesc('id')->get()
            ->map(fn (User $u) => $u->staffCard());

        return Inertia::render('admin/staff', [
            'staff' => $staff,
            'branches' => Branch::orderBy('id')->pluck('name'),
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

        // تُعرض كلمة المرور المولّدة للإدارة مرة واحدة (flash) لتسليمها للموظف
        return back()
            ->with('success', 'تم تسجيل الموظف')
            ->with('generatedPassword', ['email' => $user->email, 'password' => $plainPassword]);
    }

    // تعديل موظف قائم (الدور/الصلاحيات/الفرع/القسم/الأجر/التواصل) — لا يمسّ كلمة المرور
    public function update(Request $request, User $user): RedirectResponse
    {
        // يُعدَّل الموظفون فقط (لا عملاء)
        abort_if($user->role === Role::Client, 403);

        $data = $request->validate($this->rules($user), $this->messages());

        $user->update($this->attributes($data) + ['avatar_initials' => self::initials($data['name'])]);
        $user->syncPermissions(Permission::whereIn('name', $this->permsForRole($data['role'], $data['perms'] ?? []))->get());

        return back()->with('success', 'تم تحديث بيانات الموظف');
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
            'mobile' => ['required', 'regex:/^05\d{8}$/', Rule::unique('users', 'phone')->where('role', $role)->ignore($ignore?->id)],
            'nid' => ['required', 'regex:/^\d{10}$/', Rule::unique('users', 'national_id')->where('role', $role)->ignore($ignore?->id)],
            // الفرع إلزامي للموظف/المحامي (عزل الرؤية بالفرع يتطلّب ربطهم بفرع صراحةً)
            'branch' => ['required_if:role,employee,lawyer', 'nullable', 'string', 'max:120'],
            'dept' => ['nullable', 'string', 'max:120'],
            'join' => ['nullable', 'date'],
            'start' => ['nullable', 'string', 'max:8'],
            'end' => ['nullable', 'string', 'max:8'],
            'payType' => ['required', 'string', 'in:salary,pct,both,session'],
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
            'mobile.regex' => 'رقم الجوال يجب أن يبدأ بـ 05 ويتكوّن من 10 أرقام.',
            'mobile.unique' => 'يوجد حساب بهذا الدور لنفس الجوال. اختر دوراً مختلفاً.',
            'email.unique' => 'البريد الإلكتروني مستخدم في حساب آخر (لكل حساب بريد مختلف).',
        ];
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
            'phone' => Phone::mask((string) $users->first()->phone), // مُقنَّع — لا يُكشف الجوال كاملاً
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
            'branch' => $data['branch'] ?? null,
            'department' => $data['dept'] ?? null,
            'join_date' => $data['join'] ?? null,
            'work_start' => $data['start'] ?? null,
            'work_end' => $data['end'] ?? null,
            'pay_type' => $data['payType'],
            'salary' => in_array($data['payType'], ['salary', 'both'], true) ? ($data['salary'] ?? 0) : 0,
            'pay_pct' => in_array($data['payType'], ['pct', 'both'], true) ? ($data['pct'] ?? null) : null,
            'session_fee' => $data['payType'] === 'session' ? ($data['session'] ?? null) : null,
        ];
    }

    // تفعيل/إيقاف الموظف (يطابق toggleStaff) — للموظفين فقط (لا عملاء ولا إدارة)
    public function toggle(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isAdmin(), 403);
        abort_if($user->role === Role::Client, 403);
        $user->update(['status' => $user->isActive() ? 'suspended' : 'active']);

        return back()->with('success', $user->isActive() ? 'تم تفعيل الموظف' : 'تم إيقاف الموظف');
    }

    // معاينة لوحة الموظف بصلاحياته (يطابق previewStaff) — إمبرسنيشن مؤمّن
    public function preview(Request $request, User $user): RedirectResponse
    {
        // موظف/محامٍ فقط — لا معاينة إدارة أو عميل
        abort_unless(in_array($user->role, [Role::Employee, Role::Lawyer], true), 403);

        $admin = $request->user();
        $request->session()->put('impersonator_id', $admin->id);
        Auth::login($user);
        $request->session()->regenerate(); // منع session fixation
        Log::info('impersonation.start', ['admin_id' => $admin->id, 'target_id' => $user->id]);

        return redirect($user->role->home());
    }

    // أول حرفَي كلمتين (بعد تنظيف «أ.») — يطابق توليد avatar في staff.tsx
    private static function initials(string $name): string
    {
        $clean = preg_replace('/^أ\.?\s*/u', '', trim($name));
        $parts = preg_split('/\s+/u', $clean);

        return mb_substr($parts[0] ?? '', 0, 1).(isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1) : '');
    }
}
