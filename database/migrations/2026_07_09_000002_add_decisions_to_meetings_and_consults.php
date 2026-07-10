<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مخرجات الجلسة الحقيقية: القرارات المستخرجة + علم توليد المهام (منع التكرار)
 * على الاجتماعات والاستشارات معاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['meetings', 'consults'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->json('decisions')->nullable()->after('summary');
                $t->boolean('tasks_created')->default(false)->after('decisions');
            });
        }
    }

    public function down(): void
    {
        foreach (['meetings', 'consults'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['decisions', 'tasks_created']);
            });
        }
    }
};
