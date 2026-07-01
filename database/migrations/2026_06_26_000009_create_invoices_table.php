<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // العميل صاحب الفاتورة
            $table->string('number')->unique();             // INV-2026-312
            $table->string('description');                  // أتعاب قضية · CASE-2026-0001
            $table->integer('amount');                      // 23000
            $table->string('status')->default('مستحقة');
            $table->string('tone', 16)->default('b-amber'); // لون الشارة
            $table->string('due_label');                    // تستحق قبل 02 يوليو
            $table->boolean('paid')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
