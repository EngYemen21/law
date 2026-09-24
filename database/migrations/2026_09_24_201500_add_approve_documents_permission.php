<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * إضافة صلاحية «اعتماد الصياغة القانونية» لتقييد اعتماد لوائح ومستندات محرر الصياغة للموظفين
 * ومنحها تلقائياً للمحامين لضمان استمرارية الصلاحيات لديهم.
 */
return new class extends Migration
{
    private const PERM_NAME = 'اعتماد الصياغة القانونية';

    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        Permission::findOrCreate(self::PERM_NAME, 'web');

        // منح الصلاحية لجميع المحامين الحاليين في النظام
        User::where('role', 'lawyer')->each(function (User $lawyer) {
            if (! $lawyer->hasPermissionTo(self::PERM_NAME)) {
                $lawyer->givePermissionTo(self::PERM_NAME);
            }
        });

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        $registrar = app(PermissionRegistrar::class);

        $permission = Permission::where('name', self::PERM_NAME)->where('guard_name', 'web')->first();
        if ($permission !== null) {
            $permission->roles()->detach();
            $permission->users()->detach();
            $permission->delete();
        }

        $registrar->forgetCachedPermissions();
    }
};
