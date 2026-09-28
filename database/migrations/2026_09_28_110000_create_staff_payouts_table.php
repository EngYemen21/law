<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سجلّ الصرف للموظّفين** — ما دفعه المكتب فعلاً (قرار المالك 2026-09-28). القيد لا يُعدَّل ولا يُحذف:
 * يُلغى بسببٍ ومن ألغاه (`voided_*`)، فيبقى الأثر الماليّ قابلاً للتتبّع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16);                 // App\Enums\PayoutKind
            $table->unsignedInteger('amount');          // ر.س
            $table->char('period', 7);                  // YYYY-MM — الشهر الذي يخصّه الصرف
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('executions')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->date('paid_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_payouts');
    }
};
