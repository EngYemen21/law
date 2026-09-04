<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فصل «مادّة Zoom» عن «الملخّص الذي يصل العميل» — نظير `meetings.zoom_summary`.
 *
 * كان `ConsultSummary::pull` يكتب مخرج Zoom في `consults.summary` **مباشرةً** لأن
 * الاستشارات وحدها بلا عمود `zoom_summary`. ومع مسار الاعتماد صار ذلك يعني أن
 * ملخّصاً اعتمده محامٍ وقرأه العميل يُستبدَل صامتاً بمخرج نموذجٍ لم يمرّ به أحد.
 *
 * وبعد هذا العمود يصير مخرج Zoom **مادّةً** تُحفظ دائماً، ولا يُلمس `summary` إلّا
 * إن كان قالبياً أو فارغاً — وهي القاعدة المطبَّقة في `MeetingSummary` حرفياً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->text('zoom_summary')->nullable()->after('session_finalized_at');
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn('zoom_summary');
        });
    }
};
