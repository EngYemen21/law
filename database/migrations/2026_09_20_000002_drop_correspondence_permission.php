<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * **حذف صلاحيّة «المخاطبات» من القاعدة** مع إسقاط وحدة المخاطبات (قرار المالك 2026-09-20).
 *
 * لماذا مهاجرة لا مجرّد حذفٍ من كتالوج `App\Support\Permissions`: الحذف من الكتالوج
 * لا يمسّ القاعدة. `PermissionSeeder::pruneStalePermissions` ينظّفها فعلاً — لكنّه لا
 * يعمل إلّا حين تُشغَّل البذور، والنشر على الخادم `migrate` وحدها. فتبقى الصلاحيّة صفّاً
 * في `permissions` ومُسنَدةً لكلّ محامٍ ودورٍ نالها: تُجيب `hasPermissionTo` بنعم، وتظهر
 * مؤشَّرةً في شاشة صلاحيّات الموظّف، وتوحي بباب لم يعد له مسار.
 *
 * الاسم نصّ حرفيّ لا ثابتٌ من الكتالوج: الكتالوج لم يعد يعرّفها، ومعنى المهاجرة يجب
 * ألّا يتغيّر بتغيّره.
 *
 * و`down()` يعيد إنشاء الصلاحيّة فقط — الإسناد لمن كان يحملها يضيع بلا مصدر اشتقاق،
 * كما في كلّ مهاجرةٍ مدمّرة في هذا المشروع.
 */
return new class extends Migration
{
    private const NAME = 'المخاطبات';

    public function up(): void
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

    public function down(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        Permission::findOrCreate(self::NAME, 'web');

        $registrar->forgetCachedPermissions();
    }
};
