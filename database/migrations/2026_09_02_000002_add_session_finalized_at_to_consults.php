<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حارس تكرار التنبيه حين تنتهي جلسةٌ بلا ملاحظات مدوَّنة.
 *
 * الجلسة تُختَم من مسارين — زرّ «إنهاء» وويبهوك Zoom `meeting.ended` — وقد يصلان
 * معاً. وثلاثة تنبيهات عن جلسةٍ واحدة تُقرأ ثلاث جلسات، فيُهمَل التنبيه كضجيج.
 *
 * ولا يصلح `summary` حارساً: هو `null` عمداً حين تنتهي الجلسة بلا تدوين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->timestamp('session_finalized_at')->nullable()->after('summary_ai_source');
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn('session_finalized_at');
        });
    }
};
