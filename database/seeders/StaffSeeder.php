<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * موظفو التصميم (STAFF) كمستخدمين حقيقيين بأدوارهم وصلاحياتهم وبيانات عملهم.
 */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        // ضمان رؤية الصلاحيات المبذورة حديثاً (تفادي ذاكرة spatie داخل نفس العملية)
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $staff = [
            [
                'name' => 'منيرة الحربي', 'email' => 'employee@salasel.test', 'role' => Role::Employee,
                'avatar_initials' => 'م ح', 'job_title' => 'موظف خدمة عملاء',
                'branch' => 'الفرع الرئيسي — جدة', 'department' => 'خدمة العملاء',
                'pay_type' => 'salary', 'salary' => 7000, 'national_id' => '1023456789',
                'phone' => '0551234501', 'join_date' => '2025-09-01', 'work_start' => '08:00', 'work_end' => '16:00',
                'perms' => ['إدارة التذاكر', 'الرد على العملاء', 'جدولة المواعيد', 'تحويل التذاكر', 'استقبال الاستشارات', 'إدارة المواعيد والحجوزات', 'إرسال دعوات الاجتماعات'],
            ],
            [
                'name' => 'أ. سارة القحطاني', 'email' => 'lawyer@salasel.test', 'role' => Role::Lawyer,
                'avatar_initials' => 'س ق', 'title' => 'أ.', 'job_title' => 'محامٍ',
                'branch' => 'الفرع الرئيسي — جدة', 'department' => 'القضايا التجارية',
                'pay_type' => 'both', 'salary' => 12000, 'pay_pct' => 10, 'national_id' => '1098765432',
                'phone' => '0551234502', 'join_date' => '2024-03-15', 'work_start' => '09:00', 'work_end' => '17:00',
                'perms' => ['المساعد القانوني', 'اعتماد الملخصات', 'إدارة القضايا والأتعاب', 'استقبال الاستشارات', 'إجراء الجلسات المرئية', 'تشغيل تلخيص الفريق القانوني', 'إدارة الاجتماعات'],
            ],
            [
                'name' => 'أ. خالد المالكي', 'email' => 'k.malki@salasel.test', 'role' => Role::Lawyer,
                'avatar_initials' => 'خ م', 'title' => 'أ.', 'job_title' => 'محامٍ',
                'branch' => 'فرع الرياض', 'department' => 'العقارات',
                'pay_type' => 'session', 'session_fee' => 800, 'national_id' => '1055667788',
                'phone' => '0551234503', 'join_date' => '2025-01-10', 'work_start' => '10:00', 'work_end' => '18:00',
                'perms' => ['المساعد القانوني', 'إدارة القضايا والأتعاب'],
            ],
        ];

        foreach ($staff as $s) {
            $perms = $s['perms'];
            unset($s['perms']);

            $user = User::updateOrCreate(
                ['email' => $s['email']],
                $s + ['password' => Hash::make('password'), 'status' => 'active', 'email_verified_at' => now()],
            );
            // تمرير نماذج Permission (لا أسماء) لتفادي بحث spatie المخبّأ أثناء نفس عملية البذر
            $user->syncPermissions(Permission::whereIn('name', $perms)->get());
        }
    }
}
