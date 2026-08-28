<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تذكرة واحدة ⇒ قضية واحدة، بقيد قاعدة بيانات لا باتّفاق.
 *
 * كان الحارس في المتحكّمين فحص-ثمّ-تصرّف بلا قفل، و`ticket_id` مفتاحاً أجنبيّاً بلا
 * تفرّد. وثلاثة مسارات تشير إلى الإجراء (موظف · محامٍ · إدارة)، فنقرتان متزامنتان
 * تُنشئان قضيّتين لتذكرة واحدة: علاقة hasOne تُظهر واحدة لشاشات الطاقم، بينما قائمة
 * العميل تُظهر الاثنتين وشاشة الأتعاب تعرض صفّين للتسعير.
 *
 * القيد يقبل NULL متعدّداً (MySQL وSQLite كلاهما يعتبر NULL مميّزاً)، فالقضايا المُنشأة
 * خارج التحويل لا تتأثّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        // تنظيف أي ازدواج قائم: تُبقى الأقدم وتُفكّ رابطة ما بعدها (لا حذف لبيانات)
        $duplicates = DB::table('cases')
            ->select('ticket_id')
            ->whereNotNull('ticket_id')
            ->groupBy('ticket_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ticket_id');

        foreach ($duplicates as $ticketId) {
            $keep = DB::table('cases')->where('ticket_id', $ticketId)->min('id');
            DB::table('cases')->where('ticket_id', $ticketId)->where('id', '!=', $keep)
                ->update(['ticket_id' => null]);
        }

        Schema::table('cases', function (Blueprint $table) {
            $table->unique('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropUnique(['ticket_id']);
        });
    }
};
