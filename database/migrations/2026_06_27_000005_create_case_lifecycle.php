<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('pleading_status', 20)->default('none')->after('fee_status'); // none | pending_lawyer | approved
            $table->text('ruling')->nullable()->after('pleading_status');                 // منطوق الحكم عند صدوره
        });

        // جلسات القضية — يجدولها المحامي ويسجّل نتائجها، ويراها العميل
        Schema::create('case_hearings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('title');                       // الجلسة الأولى / جلسة المرافعة
            $table->string('day');                         // الخميس 02 يوليو
            $table->string('time', 32)->nullable();        // 10:00 ص
            $table->string('court')->nullable();           // الدائرة التجارية الأولى
            $table->string('status', 20)->default('مجدولة'); // مجدولة | منعقدة | مؤجلة
            $table->text('outcome')->nullable();           // ما جرى في الجلسة
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_hearings');
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn(['pleading_status', 'ruling']);
        });
    }
};
