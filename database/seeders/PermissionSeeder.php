<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * يبذر صلاحيات spatie الـ23 وأدوار القوالب الخمسة (خدمة عملاء/محامٍ/إداري/الإدارة العليا/مدير).
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->pruneStalePermissions();

        // إعادة تحميل الذاكرة بعد إنشاء الصلاحيات وقبل إسنادها للأدوار
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(Permissions::PRESETS) as $preset) {
            $role = SpatieRole::findOrCreate($preset, 'web');
            // نماذج (لا أسماء) لتفادي بحث spatie المخبّأ داخل نفس العملية
            $role->syncPermissions(Permission::whereIn('name', Permissions::preset($preset))->get());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * حذف الصلاحيات التي خرجت من الكتالوج — «إدارة الفروع» مثالاً بعد إزالة كيان الفرع.
     *
     * البذّار كان يُنشئ ولا يحذف، وsyncPermissions أدناه يفصل الصلاحية عن أدوار القوالب فقط:
     * فيبقى الصفّ قائماً في permissions ويبقى مُسنَداً مباشرةً لكل مستخدم لم يُعدَّل بعد
     * (المستخدمون يُزامَنون عند التعديل فقط) ⇒ صلاحية شبح حيّة على قواعد الإنتاج القائمة.
     * نفكّ الإسناد صراحةً ولا نتّكل على تسلسل المفاتيح الأجنبية وحده.
     */
    private function pruneStalePermissions(): void
    {
        $stale = Permission::where('guard_name', 'web')
            ->whereNotIn('name', Permissions::all())
            ->get();

        foreach ($stale as $permission) {
            $permission->roles()->detach();
            $permission->users()->detach();
            $permission->delete();
        }
    }
}
