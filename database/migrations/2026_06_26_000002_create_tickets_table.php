<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // العميل صاحب التذكرة
            $table->string('number')->unique();        // SB-2026-1042
            $table->string('type');                     // نزاع تجاري
            $table->string('department')->nullable();   // القسم التجاري
            $table->string('status')->default('قيد الدراسة');
            $table->string('tone', 16)->default('b-blue'); // لون الشارة
            $table->string('last_message')->nullable();    // معاينة آخر رد في القائمة
            $table->string('date_label')->nullable();      // وصف زمني للعرض (قبل ساعتين)
            $table->timestamps();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('who', 16);          // client | ai | staff | lawyer | admin | system | note
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->text('body');               // قد يحوي HTML بسيطاً
            $table->string('time_label', 16)->nullable(); // وقت العرض (10:02 ص)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
