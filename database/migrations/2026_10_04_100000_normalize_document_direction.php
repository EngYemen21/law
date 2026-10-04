<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **اتّجاه المستند قيمتان لا ثلاث** (`App\Enums\DocumentDirection`: `out` · `up`).
 *
 * بيانات التجربة كتبت `in` للوارد من العميل، والرفع الحيّ يكتب `up` وصفحة «المستندات» تقرأه — فلم يظهر
 * الوارد في «مستنداتك المرفوعة» وعرضته الرئيسيّة «صادراً». `in` هو `up` معنىً؛ ولا كاتب في النظام لغير القيمتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documents')) {
            return;
        }

        // الوارد بأيّ تسميةٍ كُتب (`in` في البذر، «وارد» في مُركّبة اختبار) مرفوعٌ؛ و«صادر» صادرٌ
        DB::table('documents')->whereIn('direction', ['in', 'وارد'])->update(['direction' => 'up']);
        DB::table('documents')->where('direction', 'صادر')->update(['direction' => 'out']);
    }

    public function down(): void
    {
        // توحيدٌ لقيمةٍ شاذّة — لا يُعاد
    }
};
