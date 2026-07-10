<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();          // العميل صاحب الاستشارة
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();      // التذكرة المصدر
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete(); // الموعد المرتبط
            $table->string('ref')->unique();               // CN-2026-1042
            $table->string('subject');                     // نزاع تجاري مع مورّد
            $table->string('channel');                     // مرئية / حضورية / هاتفية
            $table->string('lawyer');                      // أ. سارة القحطاني
            $table->string('day');                         // الاثنين 29 يونيو
            $table->string('time');                        // 11:30 ص
            $table->string('when_label');                  // الاثنين 29 يونيو · 11:30 ص
            $table->string('branch')->nullable();          // للحضورية
            $table->string('phone')->nullable();           // للهاتفية
            $table->string('status')->default('تم تحديد موعد الاستشارة'); // من CONSULT_STATES
            $table->string('session')->default('بانتظار الجلسة');          // بانتظار الجلسة / جلسة جارية / منتهية
            $table->text('session_notes')->nullable();     // ملاحظات المستشار أثناء الجلسة
            $table->text('summary')->nullable();           // ملخص الاستشارة (يولّده الفريق القانوني)
            $table->string('duration_label')->nullable();  // 05:32
            $table->unsignedInteger('price')->default(0);  // قبل الضريبة
            $table->unsignedInteger('vat')->default(0);    // 15%
            $table->unsignedInteger('total')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consults');
    }
};
