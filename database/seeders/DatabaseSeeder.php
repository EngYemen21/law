<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Specialties;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * بذرة الحسابات الأساسية فقط — بلا أي بيانات تشغيلية تجريبية.
 * أربعة حسابات (الإدارة/المحامي/الموظف/العميل):
 *   الإدارة العليا 1000000001 · المحامي 1000000002 · الموظف 1000000003 · العميل 1000000004.
 * المحامي: كل صلاحيات دوره + دور «محامٍ» + مسنَد إليه كل الأقسام (تخصّص عام).
 * الموظف: كل صلاحيات دوره + دور «خدمة عملاء».
 * كلمة المرور للجميع: password — ورمز OTP التطويري: 1234.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // الأساس: صلاحيات spatie وأدوار القوالب
        $this->call(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1) الإدارة العليا (تتجاوز الصلاحيات عبر Gate::before) — دخول: 1000000001
        $this->makeUser([
            'name' => 'الإدارة العليا', 'email' => 'kfykfy2020@gmail.com', 'role' => Role::Admin,
            'avatar_initials' => 'إ ع', 'job_title' => 'مدير عام',
            'national_id' => '1000000001', 'phone' => '+966537434000',
        ]);

        // 2) المحامي — دخول: 1000000002 — كل صلاحيات دوره + دور «محامٍ» + مسنَد إليه كل الأقسام
        $lawyer = $this->makeUser([
            'name' => 'المحامي', 'email' => 'law@salasel.sa', 'role' => Role::Lawyer,
            'title' => 'أ.', 'job_title' => 'محامٍ',
            'department' => Specialties::ALL_DEPARTMENTS,
            'work_start' => '09:00', 'work_end' => '17:00', 'avatar_initials' => 'مح',
            'national_id' => '1000000002', 'phone' => '+966537434000',
        ]);
        $lawyer->syncRoles(['محامٍ']);
        $lawyer->syncPermissions(
            Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['lawyer'])->get()
        );

        // 3) الموظف — دخول: 1000000003 — كل صلاحيات دوره + دور «خدمة عملاء»
        //    كي تكتمل الرحلة أمام الأدوار الثلاثة
        $employee = $this->makeUser([
            'name' => 'الموظف', 'email' => 'emp@salasel.sa', 'role' => Role::Employee,
            'job_title' => 'موظف خدمة عملاء',
            'department' => 'خدمة العملاء',
            'work_start' => '08:00', 'work_end' => '16:00', 'avatar_initials' => 'مو',
            'national_id' => '1000000003', 'phone' => '+966537434000',
        ]);
        $employee->syncRoles(['خدمة عملاء']);
        $employee->syncPermissions(
            Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['employee'])->get()
        );

        // 4) العميل — دخول: 1000000004
        $this->makeUser([
            'name' => 'العميل', 'email' => 'm.bander.it@gmail.com', 'role' => Role::Client,
            'avatar_initials' => 'عم', 'national_id' => '1000000004', 'phone' => '+967779475324',
        ]);

        // البيانات التشغيلية التجريبية (تذاكر، قضايا، مواعيد، تنفيذ، استشارات) لم تعد تُبذَر هنا.
        //
        // كان `DemoDataSeeder` يُستدعى تلقائياً، فـ`db:seed` أو `migrate:fresh --seed` على قاعدة
        // إنتاج يحقن ~55 سجلًّا وهميًّا و10 حسابات كلمة مرورها الموحّدة `password` وسط بيانات
        // المكتب الحقيقية. الآن `db:seed` يبذر الصلاحيات والحسابات الأساسية الأربعة فقط،
        // فهو آمن على الإنتاج بلا أي احتراز.
        //
        // البيانات التجريبية صارت اختيارية وصريحة (بيئة التطوير وحدها):
        //     php artisan db:seed --class=DemoDataSeeder

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * إنشاء/تحديث حساب فعّال بكلمة المرور الموحّدة — متقارب (idempotent) على أي قاعدة قائمة:
     * المرساة قيد التفرّد (رقم الهوية + الدور) لا البريد؛ فإن شغل البريدَ صفٌّ آخر مختلف
     * حُرّر بريده (لاحقة أرشفة) بدل تحويله للدور الجديد — كانت المطابقة بالبريد أولاً تصطدم
     * بقيد (الهوية+الدور) حين يحمل البريدَ مستخدمٌ قديم غير صفّ الهويّة.
     */
    private function makeUser(array $attrs): User
    {
        $byIdentity = User::where('national_id', $attrs['national_id'])->where('role', $attrs['role'])->first();
        $byEmail = User::where('email', $attrs['email'])->first();

        // صفّان مختلفان يتنازعان: صفّ يحمل الهويّة+الدور وآخر يحمل البريد — يُؤرشف بريد الأخير
        // ليتحرّر للحساب القانوني (تحويله للدور الجديد كان يصطدم بقيد الهويّة+الدور)
        if ($byIdentity && $byEmail && $byIdentity->isNot($byEmail)) {
            $byEmail->update(['email' => 'archived+'.$byEmail->id.'.'.$byEmail->email]);
            $byEmail = null;
        }

        $payload = array_merge($attrs, [
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $target = $byIdentity ?? $byEmail;
        if ($target) {
            $target->update($payload);

            return $target->fresh();
        }

        return User::create($payload);
    }
}
