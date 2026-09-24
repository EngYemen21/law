<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * **حذف صلاحيّة «إشعارات العملاء» من القاعدة** بعد إزالة ميزتها (قرار المالك 2026-09-24).
 *
 * الميزة كانت تتيح للإدارة عرضَ إشعارات أيّ عميل وإرسالَ إشعارٍ مكتوبٍ إليه
 * (‏`Admin\ClientNotifController` + `admin/clientnotifs.tsx` + ثلاثة مسارات). أُزيلت كلّها،
 * **ولا بديل لها**: كلّ إشعارٍ يصل العميل اليوم يُطلقه مسار عملٍ آليّاً عبر `Notify::send`،
 * ولا موضع يكتب فيه إداريٌّ نصّاً لعميلٍ بعينه.
 *
 * ولماذا مهاجرة لا مجرّد حذفٍ من كتالوج `App\Support\Permissions`؟ لأنّ الحذف من الكتالوج
 * لا يمسّ القاعدة. فتبقى الصلاحيّة صفّاً في `permissions` ومُسنَدةً لكلّ من نالها: تُجيب
 * `hasPermissionTo` بنعم، وتظهر مؤشَّرةً في شاشة صلاحيّات الموظّف — **مربّعٌ يفتح باباً
 * معدوماً**. والنشر على الخادم `migrate` وحدها، والبذّار لا يعمل إلّا حين يُشغَّل يدويّاً.
 *
 * الاسم نصٌّ حرفيّ لا ثابتٌ من الكتالوج: الكتالوج لم يعد يعرّفها، ومعنى المهاجرة يجب ألّا
 * يتغيّر بتغيّره.
 *
 * و`down()` يعيد إنشاء الصلاحيّة فقط — الإسناد لمن كان يحملها يضيع بلا مصدر اشتقاق، كما في
 * نظيرتها `2026_09_20_000002_drop_correspondence_permission`.
 */
return new class extends Migration
{
    private const NAME = 'إشعارات العملاء';

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
