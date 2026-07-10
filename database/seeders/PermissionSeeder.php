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

        // إعادة تحميل الذاكرة بعد إنشاء الصلاحيات وقبل إسنادها للأدوار
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(Permissions::PRESETS) as $preset) {
            $role = SpatieRole::findOrCreate($preset, 'web');
            // نماذج (لا أسماء) لتفادي بحث spatie المخبّأ داخل نفس العملية
            $role->syncPermissions(Permission::whereIn('name', Permissions::preset($preset))->get());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
