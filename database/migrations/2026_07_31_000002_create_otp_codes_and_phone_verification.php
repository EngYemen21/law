<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // رموز التحقّق (OTP) — تُخزَّن مُجزّأةً مع انتهاء وحدّ محاولات ومنع إعادة الاستخدام
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null للتسجيل الجديد
            $table->string('phone');
            $table->string('purpose', 20); // login | register
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'purpose']);
        });

        // تأكيد ملكيّة الجوال + تفرّد الهويّة والجوال (لبحث الدخول وتفرّد التسجيل)
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->unique('national_id'); // nullable unique — يسمح بعدّة NULL
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['national_id']);
            $table->dropUnique(['phone']);
            $table->dropColumn('phone_verified_at');
        });

        Schema::dropIfExists('otp_codes');
    }
};
