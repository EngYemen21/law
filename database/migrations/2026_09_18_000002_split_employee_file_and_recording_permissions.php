<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * **فصل ثلاث قدراتٍ للموظّف عن صلاحيّاتٍ أوسع كانت تحملها** (قرار المالك 2026-09-18).
 *
 * - «تسجيل الأحكام»: تُنشأ ولا تُمنح لأحد — كان كلُّ حامل «إجراءات المحكمة والجلسات» يسجّل
 *   حكماً على أيّ قضيّة، والمالك أراد الحكم للمحامي المسنَد والإدارة ومن تختاره.
 * - «تنزيل مرفقات الملفات» و«تشغيل تسجيلات الجلسات»: تُمنح لكلّ موظّفٍ كان يبلغها اليوم،
 *   فلا ينقطع عمل أحد، ثمّ تسحبها الإدارة ممّن تشاء من تبويب الموظّفين.
 *
 * من كان يبلغها: المرفقات بـ«إدارة التذاكر» (مرفقات التذكرة) أو «إدارة القضايا والأتعاب»
 * (القضيّة والتنفيذ) — `ConversationFiles::canDownload` قبل الفصل. والتسجيلات بـ«استقبال
 * الاستشارات» أو «إرسال دعوات الاجتماعات» — مجموعتا مساراتها في `routes/web.php`.
 * الأسماء نصوصٌ حرفيّة لا ثوابت الكتالوج، فلا يتغيّر معنى الهجرة إن تغيّر الكتالوج لاحقاً.
 */
return new class extends Migration
{
    private const GRANTS = [
        'تنزيل مرفقات الملفات' => ['إدارة التذاكر', 'إدارة القضايا والأتعاب'],
        'تشغيل تسجيلات الجلسات' => ['استقبال الاستشارات', 'إرسال دعوات الاجتماعات'],
    ];

    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        Permission::findOrCreate('تسجيل الأحكام', 'web');
        foreach (array_keys(self::GRANTS) as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $registrar->forgetCachedPermissions();

        // `hasAnyPermission` يشمل ما يصل عبر قوالب الأدوار لا المباشر وحده
        User::where('role', 'employee')->each(function (User $employee) {
            foreach (self::GRANTS as $granted => $whoHadIt) {
                $had = Permission::whereIn('name', $whoHadIt)->where('guard_name', 'web')->pluck('name')->all();
                if ($had !== [] && $employee->hasAnyPermission($had) && ! $employee->hasPermissionTo($granted)) {
                    $employee->givePermissionTo($granted);
                }
            }
        });

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        $registrar = app(PermissionRegistrar::class);

        foreach (['تسجيل الأحكام', ...array_keys(self::GRANTS)] as $name) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();
            if ($permission !== null) {
                $permission->roles()->detach();
                $permission->users()->detach();
                $permission->delete();
            }
        }

        $registrar->forgetCachedPermissions();
    }
};
