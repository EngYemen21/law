<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تعدّد الحسابات المرتبط بالهُويّة: الشخص الواحد (نفس national_id + phone) يملك حساباً واحداً لكل دور.
 * نستبدل التفرّد العالميّ على national_id/phone بتفرّد مركّب (العمود + role)،
 * فيُسمح بمشاركة الهُويّة/الجوال عبر أدوار مختلفة ويُمنع تكرار نفس الدور. البريد يبقى فريداً عالميّاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['national_id']);
            $table->dropUnique(['phone']);

            $table->unique(['national_id', 'role']);
            $table->unique(['phone', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['national_id', 'role']);
            $table->dropUnique(['phone', 'role']);

            $table->unique('national_id');
            $table->unique('phone');
        });
    }
};
