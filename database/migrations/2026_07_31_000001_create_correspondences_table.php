<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// المخاطبات الرسميّة مع الجهات/المحاكم عبر النظام الخارجيّ — دورة 7 مراحل + إفادة العميل + ربط بالتنفيذ/القضايا
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correspondences', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();                 // MKH-YYYY-####
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // العميل
            $table->foreignId('assigned_lawyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lawyer')->nullable();                // اسم المحامي للعرض
            $table->string('branch')->nullable();                // عزل الفرع
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained()->nullOnDelete();

            $table->string('direction')->default('صادرة');       // صادرة | واردة
            $table->string('entity');                            // الجهة/المحكمة
            $table->string('subject');
            $table->string('channel')->nullable();               // قناة الإرسال (النظام الخارجيّ)
            $table->text('body')->nullable();

            $table->unsignedTinyInteger('stage')->default(0);    // 0..6 (CORR_FLOW)
            $table->string('status')->default('إنشاء المخاطبة'); // نصّ مشتقّ من المرحلة
            $table->string('tone')->default('b-grey');
            $table->string('date_label')->nullable();
            $table->string('due_label')->nullable();

            // النظام الخارجيّ (adapter)
            $table->string('ext_ref')->nullable();
            $table->string('ext_status')->nullable();
            $table->timestamp('ext_synced_at')->nullable();
            $table->text('reply_body')->nullable();

            // إفادة العميل
            $table->boolean('briefed')->default(false);
            $table->text('brief_note')->nullable();
            $table->boolean('brief_requested')->default(false);

            $table->json('audit')->nullable();                   // [{a, by, t}]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correspondences');
    }
};
