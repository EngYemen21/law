<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * بذرة الحسابات الحقيقية فقط — بلا أي بيانات تشغيلية وهمية.
 * تنشئ: الإدارة العليا + عميل + (محامٍ وموظف) لكل فرع، مع الصلاحيات الصحيحة لكل دور.
 * كلمة المرور للجميع: password.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // الأساس: صلاحيات spatie وأدوار القوالب، ثم الفروع
        $this->call(PermissionSeeder::class);
        $this->call(BranchSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // الإدارة العليا (تتجاوز الصلاحيات عبر Gate::before)
        // ملاحظة: national_id + phone إلزاميان للدخول برقم الهويّة + رمز SMS (OTP)
        $this->makeUser([
            'name' => 'الإدارة العليا', 'email' => 'admin@salasel.sa', 'role' => Role::Admin,
            'avatar_initials' => 'إ ع', 'branch' => Branch::DEFAULT, 'job_title' => 'مدير عام',
            'national_id' => '1000000001', 'phone' => '0500000001',
        ]);

        // حساب محامٍ إضافيّ لنفس شخص الإدارة العليا (نفس الهُويّة + الجوال، بريد مختلف)
        // — لتوضيح «الشخص الواحد بأكثر من دور»: يظهر مُنتقي الحساب عند الدخول + «تبديل الحساب».
        $adminLawyer = $this->makeUser([
            'name' => 'الإدارة العليا', 'email' => 'admin.lawyer@salasel.sa', 'role' => Role::Lawyer,
            'title' => 'أ.', 'job_title' => 'محامٍ', 'branch' => Branch::DEFAULT, 'department' => 'القضايا التجارية',
            'work_start' => '09:00', 'work_end' => '17:00', 'avatar_initials' => 'إ ع',
            'national_id' => '1000000001', 'phone' => '0500000001',
        ]);
        $adminLawyer->syncPermissions(
            Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['lawyer'])->get()
        );

        // عميل
        $this->makeUser([
            'name' => 'العميل', 'email' => 'client@salasel.sa', 'role' => Role::Client,
            'avatar_initials' => 'عم', 'national_id' => '1000000002', 'phone' => '0500000002',
        ]);

        // محامٍ + موظف لكل فرع (بصلاحيات القالب الصحيحة لكل دور)
        $branches = [
            ['name' => 'الفرع الرئيسي — جدة', 'slug' => 'jeddah', 'city' => 'جدة', 'dept' => 'القضايا التجارية', 'seq' => 1],
            ['name' => 'فرع الرياض', 'slug' => 'riyadh', 'city' => 'الرياض', 'dept' => 'الأحوال الشخصية', 'seq' => 2],
            ['name' => 'فرع الدمام', 'slug' => 'dammam', 'city' => 'الدمام', 'dept' => 'العقارات', 'seq' => 3],
        ];

        foreach ($branches as $b) {
            $lawyer = $this->makeUser([
                'name' => 'محامي فرع '.$b['city'], 'email' => "lawyer.{$b['slug']}@salasel.sa",
                'role' => Role::Lawyer, 'title' => 'أ.', 'job_title' => 'محامٍ',
                'branch' => $b['name'], 'department' => $b['dept'],
                'work_start' => '09:00', 'work_end' => '17:00', 'avatar_initials' => 'مح',
                'national_id' => '10000000'.$b['seq'].'1', 'phone' => '05000000'.$b['seq'].'1',
            ]);
            $lawyer->syncPermissions(
                Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['lawyer'])->get()
            );

            $employee = $this->makeUser([
                'name' => 'موظف فرع '.$b['city'], 'email' => "employee.{$b['slug']}@salasel.sa",
                'role' => Role::Employee, 'job_title' => 'موظف خدمة عملاء',
                'branch' => $b['name'], 'department' => 'خدمة العملاء',
                'work_start' => '08:00', 'work_end' => '16:00', 'avatar_initials' => 'مو',
                'national_id' => '10000000'.$b['seq'].'2', 'phone' => '05000000'.$b['seq'].'2',
            ]);
            $employee->syncPermissions(
                Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['employee'])->get()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** إنشاء/تحديث حساب فعّال بكلمة المرور الموحّدة. */
    private function makeUser(array $attrs): User
    {
        return User::updateOrCreate(
            ['email' => $attrs['email']],
            array_merge($attrs, [
                'password' => Hash::make('password'),
                'status' => 'active',
                'email_verified_at' => now(),
            ])
        );
    }
}
