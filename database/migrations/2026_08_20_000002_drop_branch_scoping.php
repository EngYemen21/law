<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إسقاط كيان «الفرع» نهائياً (قرار صاحب المنتج: مكتب واحد، والموظف يرى كل شيء).
 *
 * ⚠️ مهاجرة مدمّرة لا رجعة فيها: down() يعيد البنية (أعمدة + فهارس + جدول) لكن
 * القيم تضيع بلا مصدر اشتقاق — لا مفتاح أجنبي ولا مرجع آخر لاسم الفرع في المنظومة.
 * الرجوع الحقيقي = استعادة نسخة احتياطية أُخذت قبل التشغيل على الإنتاج.
 *
 * ترتيب إلزامي: تُسقط الفهارس أولاً — SQLite يفشل بـ«error in index after drop column»
 * على عمود مفهرس، ولارافل لا يسقط الفهارس تلقائياً مع العمود.
 */
return new class extends Migration
{
    /** الجداول التي تحمل عمود الفرع التنظيمي. */
    private const TABLES = ['users', 'tickets', 'cases', 'executions', 'meetings', 'correspondences', 'consults'];

    public function up(): void
    {
        // 1) الفهارس المركّبة أولاً ثم المفردة (بأسمائها الصريحة لا بمصفوفة الأعمدة)
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropIndex('tickets_branch_status_index');
            $t->dropIndex('tickets_branch_index');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropIndex('users_role_branch_index'));
        Schema::table('meetings', fn (Blueprint $t) => $t->dropIndex('meetings_branch_index'));
        Schema::table('cases', fn (Blueprint $t) => $t->dropIndex('cases_branch_index'));
        Schema::table('executions', fn (Blueprint $t) => $t->dropIndex('executions_branch_index'));

        // 2) الأعمدة
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('branch'));
        }

        // 3) الجدول نفسه
        Schema::dropIfExists('branches');
    }

    public function down(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('city');
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('branch')->nullable());
        }

        Schema::table('tickets', function (Blueprint $t) {
            $t->index('branch');
            $t->index(['branch', 'status']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->index(['role', 'branch']));
        Schema::table('meetings', fn (Blueprint $t) => $t->index('branch'));
        Schema::table('cases', fn (Blueprint $t) => $t->index('branch'));
        Schema::table('executions', fn (Blueprint $t) => $t->index('branch'));
    }
};
