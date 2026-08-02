<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// توحيد مفردات حالات التذكرة (إزالة المرادفات): «قيد الاستلام»→«جديدة»، «قيد الدراسة»→«قيد التحليل».
// يطابق TicketJourney بعد التوحيد؛ لأمان البيانات القائمة (الاختبارات تُعاد بذورها بالقيم الجديدة).
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tickets')->where('status', 'قيد الاستلام')
            ->update(['status' => 'جديدة', 'tone' => 'b-grey']);

        DB::table('tickets')->where('status', 'قيد الدراسة')
            ->update(['status' => 'قيد التحليل', 'tone' => 'b-blue']);
    }

    public function down(): void
    {
        // لا رجعة: المفردتان القديمتان مرادفتان محذوفتان.
    }
};
