<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // العميل صاحب طلب التنفيذ
            $table->string('number')->unique();        // تنفيذ-5521
            $table->string('subject');                  // تنفيذ حكم مالي
            $table->string('status')->default('جارٍ');
            $table->string('tone', 16)->default('b-blue'); // لون الشارة
            $table->string('last_action')->nullable();     // آخر إجراء للعرض
            $table->timestamps();
        });

        Schema::create('execution_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
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
        Schema::dropIfExists('execution_messages');
        Schema::dropIfExists('executions');
    }
};
