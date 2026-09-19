<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **تسبيب إغلاق القضية القضائية** (المرحلة ب — 2026-09-16).
 *
 * يضيف سبب الإغلاق النظامي والملاحظات وتاريخ الإغلاق لتكون كل قضية مغلقة معللة وقابلة للتدقيق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('closure_reason', 60)->nullable()->after('ruling');
            $table->text('closure_notes')->nullable()->after('closure_reason');
            $table->timestamp('closed_at')->nullable()->after('closure_notes');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn(['closure_reason', 'closure_notes', 'closed_at']);
        });
    }
};
