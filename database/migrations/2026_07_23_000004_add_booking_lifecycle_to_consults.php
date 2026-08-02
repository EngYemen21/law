<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// دورة حياة الحجز المسبق للاستشارة: طوابع التسعير والسداد (تسبق مرحلة «جديدة»).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->timestamp('priced_at')->nullable()->after('total');
            $table->timestamp('paid_at')->nullable()->after('priced_at');
            // الموعد يُختار بعد السداد، فحقول الموعد فارغة في مرحلتَي التسعير والسداد
            $table->string('day')->nullable()->change();
            $table->string('time')->nullable()->change();
            $table->string('when_label')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn(['priced_at', 'paid_at']);
            $table->string('day')->nullable(false)->change();
            $table->string('time')->nullable(false)->change();
            $table->string('when_label')->nullable(false)->change();
        });
    }
};
