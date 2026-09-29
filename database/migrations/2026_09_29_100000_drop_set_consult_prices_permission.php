<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * **حذف صلاحيّة «تحديد أسعار الاستشارات» وأسعارها المقترحة** بعد حذف تبويبها (قرار المالك 2026-09-29).
 *
 * التبويب (`Admin\PriceController` + `admin/prices.tsx`) كان يحفظ أسعاراً «مقترحة» للقنوات الثلاث
 * ونسبة الضريبة. والتسعير يتمّ لكلّ استشارةٍ على حدة من شاشات الاستشارات، فحُذف التبويب؛ والضريبة
 * انتقلت إلى «الإعدادات» (`SettingsRegistry` — المفتاح `vat_rate` نفسه، فلا تُمسّ قيمتها هنا).
 *
 * مهاجرةٌ لا حذفٌ من الكتالوج وحده — نمط `2026_09_24_220000_drop_client_notifications_permission`:
 * الصفّ يبقى في `permissions` مُسنَداً لمن ناله فيظهر في شاشة الصلاحيّات مربّعاً لبابٍ معدوم.
 * و`price_*` في `settings` صفوفٌ بلا قارئ بعد اليوم — تُحذف كي لا توحي بأسعارٍ معتمدة.
 *
 * و`down()` يعيد الصلاحيّة فقط — الإسناد والأسعار القديمة لا مصدر لاشتقاقها.
 */
return new class extends Migration
{
    private const NAME = 'تحديد أسعار الاستشارات';

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

        DB::table('settings')->whereIn('key', ['price_office', 'price_video', 'price_phone'])->delete();
    }

    public function down(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        Permission::findOrCreate(self::NAME, 'web');

        $registrar->forgetCachedPermissions();
    }
};
