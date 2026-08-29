<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قرار المراجع البشريّ — بجوار `reviewed_by`/`reviewed_at` القائمين.
 *
 * الحقلان القائمان يقولان **من** راجع و**متى**، ولا يقولان **ماذا قرّر**. فلا يُعرف
 * أقُبل المخرج كما هو أم احتاج تعديلاً أم رُفض — والفارق بين «قبول» و«تعديل ثم قبول»
 * هو مقياس الجودة الحقيقيّ: كم مرّة أصلح إنسانٌ مخرجاً قبل اعتماده.
 *
 * و`review_reason` رمزٌ معياريّ لا نصّ حرّ: الرفض بنصّ حرّ يُصلح مخرجاً واحداً ثم
 * يتبخّر، وبرمزٍ يتراكم فيكشف نمطاً يُغذّي مجموعة التقييم (P4).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_runs')) {
            return;
        }

        Schema::table('ai_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_runs', 'review_action')) {
                // accept / edit / reject / rerun / escalate — App\Services\Ai\AiReviewAction
                $table->string('review_action')->nullable()->after('reviewed_at');
            }
            if (! Schema::hasColumn('ai_runs', 'review_reason')) {
                // رمز معياريّ — App\Services\Ai\AiReviewReason
                $table->string('review_reason')->nullable()->after('review_action');
            }
            if (! Schema::hasColumn('ai_runs', 'review_note')) {
                $table->text('review_note')->nullable()->after('review_reason');
            }
            if (! Schema::hasColumn('ai_runs', 'escalated_to')) {
                $table->foreignId('escalated_to')->nullable()->after('review_note')
                    ->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->index(['review_action', 'created_at']);
            $table->index(['review_reason', 'created_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_runs')) {
            return;
        }

        Schema::table('ai_runs', function (Blueprint $table) {
            $table->dropIndex(['review_action', 'created_at']);
            $table->dropIndex(['review_reason', 'created_at']);
        });

        Schema::table('ai_runs', function (Blueprint $table) {
            foreach (['review_action', 'review_reason', 'review_note'] as $column) {
                if (Schema::hasColumn('ai_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('ai_runs', 'escalated_to')) {
                $table->dropConstrainedForeignId('escalated_to');
            }
        });
    }
};
