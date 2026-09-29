<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نوع السند التنفيذيّ على تذكرة قسم «التنفيذ» (قرار المالك 2026-09-29).
 *
 * طلب التنفيذ صار يُفتح تذكرةً لا ملفّاً مباشراً، والسند يُسأل عنه عند فتحها ثمّ ينتقل إلى
 * ملفّ التنفيذ عند اعتماد المسار (`ExecutionCreation::fromTicket`). فارغٌ لغير قسم التنفيذ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('exec_sanad', 40)->nullable()->after('claim_amount');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('exec_sanad');
        });
    }
};
