<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **روابط وفهارس ناقصة** (المجموعة ج) — لا يتغيّر بها سلوك.
 *
 * الروابط: أعمدة `*_id` بلا قيد (0 يتيمٍ عند الإضافة)؛ وحذف المحامي يُفرغ الحقل كبقيّة روابط المحامي.
 * الفهارس: لما يُستعلم في كلّ طلب (عدّاد الإشعارات غير المقروءة)، وفي أوامر الجدولة كلّ دقيقة
 * (تذكيرات الاجتماعات والجلسات والاستشارات)، وفي التقارير الماليّة وتنبيهات الاستحقاق، وتصفية السجلّين بالتاريخ.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private const INDEXES = [
        'user_notifications' => [['user_id', 'is_read']],
        'audit_logs' => [['created_at']],
        'journey_transitions' => [['created_at']],
        'meetings' => [['status', 'starts_at']],
        'case_hearings' => [['status', 'starts_at']],
        'consults' => [['starts_at']],
        'invoices' => [['paid', 'due_at'], ['paid_at']],
        'executions' => [['stage'], ['pay_due_at']],
    ];

    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $t) {
            $t->index('case_id');
            $t->foreign('case_id')->references('id')->on('cases')->nullOnDelete();
        });
        Schema::table('appointments', fn (Blueprint $t) => $t->foreign('lawyer_id')->references('id')->on('users')->nullOnDelete());
        Schema::table('consults', fn (Blueprint $t) => $t->foreign('assigned_lawyer_id')->references('id')->on('users')->nullOnDelete());
        Schema::table('meetings', fn (Blueprint $t) => $t->foreign('assigned_lawyer_id')->references('id')->on('users')->nullOnDelete());

        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $columns) {
                    $t->index($columns);
                }
            });
        }
    }

    public function down(): void
    {
        // MySQL يُسقط فهرس المفتاح الأجنبيّ الضمنيّ حين يخدمه فهرسٌ أوسع (`user_id,is_read`)؛ فيُعاد قبل حذف المركّب
        Schema::table('user_notifications', fn (Blueprint $t) => $t->index('user_id', 'user_notifications_user_id_foreign'));

        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $columns) {
                    $t->dropIndex($columns);
                }
            });
        }

        Schema::table('meetings', fn (Blueprint $t) => $t->dropForeign(['assigned_lawyer_id']));
        Schema::table('consults', fn (Blueprint $t) => $t->dropForeign(['assigned_lawyer_id']));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropForeign(['lawyer_id']));
        Schema::table('legal_documents', function (Blueprint $t) {
            $t->dropForeign(['case_id']);
            $t->dropIndex(['case_id']);
        });
    }
};
