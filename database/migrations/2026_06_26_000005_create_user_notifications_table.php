<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // العميل صاحب الإشعار
            $table->string('icon', 32);         // ticket | cal | video | card
            $table->string('tone', 16);         // t-blue | t-green | t-cyan | t-amber
            $table->text('body');               // قد يحوي HTML بسيطاً
            $table->string('time_label', 32)->nullable(); // وصف زمني (قبل ساعتين)
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
