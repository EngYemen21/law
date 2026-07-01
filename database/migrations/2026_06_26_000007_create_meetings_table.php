<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // العميل صاحب الاجتماع
            $table->string('title');                        // استشارة مرئية — نزاع تجاري
            $table->string('when_label');                   // الاثنين 29 يونيو · 11:30 ص
            $table->boolean('is_up')->default(false);       // قادم؟
            $table->boolean('has_link')->default(false);    // يوجد رابط اجتماع؟
            $table->boolean('has_minutes')->default(false); // يوجد محضر معتمد؟
            $table->boolean('has_summary')->default(false); // يوجد ملخص معتمد؟
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
