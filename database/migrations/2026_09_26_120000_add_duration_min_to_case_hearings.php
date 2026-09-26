<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **المدّة المتوقّعة لجلسة المحكمة بالدقائق** (قرار المالك 2026-09-26).
 *
 * كان التقويم يكتب لكلّ جلسةٍ نهايةً منقوشة بعد ستّين دقيقة لا يعرفها أحد. صار الطاقم يُدخل مدّةً
 * متوقّعة إن عرفها، ومنها وحدها تُشتقّ النهاية (`CaseHearing::endsAt`).
 *
 * - `nullable`: الجلسات القائمة والجديدة بلا مدّةٍ مُدخلة تبقى بلا نهاية — لا رقمَ مختلَق.
 * - `unsignedSmallInteger`: السقف في التحقّق ٦٠٠ دقيقة، والعمود يتّسع لما فوقه بلا كلفة.
 * - إضافةٌ خالصة: لا تُمسّ بيانات قائمة، و`down()` يُسقط العمود وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_hearings', function (Blueprint $t) {
            $t->unsignedSmallInteger('duration_min')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('case_hearings', function (Blueprint $t) {
            $t->dropColumn('duration_min');
        });
    }
};
