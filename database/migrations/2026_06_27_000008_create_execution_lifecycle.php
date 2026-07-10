<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->foreignId('case_id')->nullable()->after('user_id')->constrained('cases')->nullOnDelete(); // منشأ من قضية محكومة
            $table->string('assigned_lawyer')->nullable()->after('subject'); // محامي التنفيذ
            $table->string('court')->nullable()->after('assigned_lawyer');    // محكمة التنفيذ / رقم القيد
        });

        // إجراءات التنفيذ — يجرّيها المحامي (حجز/تحصيل/إخطار) ويتابعها العميل
        Schema::create('execution_procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->string('title');                        // حجز تحفظي على الحسابات
            $table->string('type', 20)->default('إجراء');    // حجز | تحصيل | إخطار | إجراء
            $table->text('detail')->nullable();
            $table->string('status', 20)->default('مجدول');  // مجدول | منفّذ | مؤجل
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_procedures');
        Schema::table('executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('case_id');
            $table->dropColumn(['assigned_lawyer', 'court']);
        });
    }
};
