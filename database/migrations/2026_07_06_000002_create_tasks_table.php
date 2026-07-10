<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// مهام المحامي — بديل حقيقي لصفحة المهام التي كانت تعيش في useState وتضيع بالتحديث
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete(); // المحامي المسؤول
            $table->string('title');
            $table->string('ref')->nullable();               // مرجع تذكرة/قضية (اختياري)
            $table->string('due')->nullable();               // نص الاستحقاق (يبقى نصاً — دَين التواريخ P1)
            $table->string('status', 20)->default('مفتوحة'); // مفتوحة/قيد العمل/منجزة
            $table->string('tone', 16)->default('b-amber');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
