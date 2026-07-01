<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // العميل صاحب الموعد
            $table->string('ext_id');                       // AP1 (المعرّف الظاهر في الواجهة)
            $table->string('type');                         // مرئية | حضورية
            $table->string('ico', 32);                      // أيقونة العرض (video | office)
            $table->string('lawyer');                       // اسم المحامي
            $table->string('day');                          // الاثنين 29 يونيو 2026
            $table->string('time', 32);                     // 11:30 ص
            $table->string('branch');                       // اجتماع إلكتروني | الرياض — حي العليا
            $table->string('status')->default('مؤكد');
            $table->string('tone', 16)->default('b-green'); // لون الشارة
            $table->string('when_kind', 8)->default('up');  // up | past
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
