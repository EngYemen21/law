<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->default('النظام');
            $table->string('user_role')->default('system'); // admin / lawyer / employee / client / system
            $table->string('action'); // e.g. "تسجيل دخول", "تعديل سعر", "إعادة جدولة", "تصدير تقرير"
            $table->string('category')->default('عام'); // أمن وحماية / استشارات / اجتماعات / قضايا وتنفيذ / مالية وفواتير / تذاكر / نظام
            $table->string('auditable_type')->nullable(); // Model class
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('auditable_ref')->nullable(); // e.g. CN-2026-1042 / CASE-102
            $table->text('description');
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('severity')->default('info'); // info / warning / critical
            $table->timestamps();

            $table->index(['category', 'created_at']);
            $table->index(['severity', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
