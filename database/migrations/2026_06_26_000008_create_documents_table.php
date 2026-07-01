<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // العميل صاحب المستند
            $table->string('name');                         // عقد_التوريد.pdf
            $table->string('meta');                         // PDF · 1.2MB · تذكرة SB-2026-1042
            $table->string('direction', 8)->default('up');  // out (صادر) | up (مرفوع)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
