<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * **«تسجيل المصروفات»** — يسجّل بها الموظّف مصروفاً يبقى بانتظار اعتماد الإدارة (قرار المالك
 * 2026-09-29). تُنشأ ولا تُمنح لأحد: تمنحها الإدارة من تبويب الموظّفين لمن تختار.
 */
return new class extends Migration
{
    private const NAME = 'تسجيل المصروفات';

    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        Permission::findOrCreate(self::NAME, 'web');
        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $permission = Permission::where('name', self::NAME)->where('guard_name', 'web')->first();
        if ($permission !== null) {
            $permission->roles()->detach();
            $permission->users()->detach();
            $permission->delete();
        }
        $registrar->forgetCachedPermissions();
    }
};
