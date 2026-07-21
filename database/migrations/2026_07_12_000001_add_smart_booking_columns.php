<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حجز الاستشارة الذكي: ترقية المواعيد/الاستشارات لوقت حقيقي (datetime) يسمح بحساب
 * التعارض ومنع الحجز المزدوج، وربط المحامي بالمعرّف، وحفظ تخصّص الاستشارة.
 * أعمدة nullable بلا قيود FK صريحة (توافقاً مع SQLite في الاختبارات؛ العلاقات في النماذج).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable();          // بداية الموعد الفعلية (مصدر التعارض)
            $table->unsignedInteger('duration_min')->default(60); // مدة الموعد بالدقائق
            $table->unsignedBigInteger('lawyer_id')->nullable();  // المحامي بالمعرّف (بجانب الاسم النصّي)
            $table->index(['lawyer_id', 'starts_at']);
        });

        Schema::table('consults', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_lawyer_id')->nullable(); // المحامي المسند بالمعرّف
            $table->string('specialty')->nullable();                       // تخصّص الاستشارة (canonical)
            $table->dateTime('starts_at')->nullable();
            $table->unsignedInteger('duration_min')->default(60);
            $table->index(['assigned_lawyer_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['lawyer_id', 'starts_at']);
            $table->dropColumn(['starts_at', 'duration_min', 'lawyer_id']);
        });

        Schema::table('consults', function (Blueprint $table) {
            $table->dropIndex(['assigned_lawyer_id', 'starts_at']);
            $table->dropColumn(['assigned_lawyer_id', 'specialty', 'starts_at', 'duration_min']);
        });
    }
};
