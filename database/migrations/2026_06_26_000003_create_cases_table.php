<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // العميل صاحب القضية
            $table->string('number')->unique();        // ق-2026-0211
            $table->string('type');                     // تجاري
            $table->string('status')->default('منظورة');
            $table->string('tone', 16)->default('b-blue'); // لون الشارة
            $table->string('update_text')->nullable();      // آخر تحديث للعرض في القائمة
            $table->string('next_hearing')->nullable();     // الجلسة القادمة
            $table->string('invoice_text')->nullable();     // الفواتير المستحقة
            $table->string('paid_text')->nullable();        // المدفوعات
            $table->timestamps();
        });

        Schema::create('case_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('who', 16);          // client | ai | staff | lawyer | admin | system | note
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->text('body');               // قد يحوي HTML بسيطاً
            $table->string('time_label', 16)->nullable(); // وقت العرض (09:05 ص)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_messages');
        Schema::dropIfExists('cases');
    }
};
