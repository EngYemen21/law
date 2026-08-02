<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إسقاط جدول otp_codes — تقنيات (Verify API) تُولّد الرمز وتخزّنه وتتحقّق منه،
 * فلا حاجة لتخزين محليّ. حالة عمليّة التحقّق (requestId) تُحفظ في الجلسة فقط.
 * (تبقى أعمدة users: phone_verified_at + فهارس تفرّد national_id/phone.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('otp_codes');
    }

    public function down(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phone');
            $table->string('purpose', 20);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['phone', 'purpose']);
        });
    }
};
