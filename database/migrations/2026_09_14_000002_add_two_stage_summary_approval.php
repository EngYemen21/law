<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **اعتماد الملخّصين على مرحلتين: المحامي ثمّ الإدارة ثمّ العميل** (قرار المالك 2026-09-14).
 *
 * إضافةٌ لا تمسّ صفّاً قائماً:
 *   - `ticket_summaries.lawyer_approved_at/by`: اعتماد المحامي لملخّص الملفّ — لا يصل العميلَ شيءٌ به؛
 *     وتبقى `status = approved` معناها «اعتمدته الإدارة ونُشر».
 *   - `ticket_summaries.edited_at`: المحامي حرّر الملخّص بيده — فلا تكتب فوقه إعادة التوليد الآليّة.
 *   - `consults.summary_lawyer_approved_at/by`: اعتماد المحامي لملخّص الجلسة؛ و`summary_approved_at`
 *     يبقى بوّابة العميل ويكتبه اعتماد الإدارة وحده.
 *
 * الملخّصات المعتمدة قبل هذا التغيير تبقى منشورةً كما هي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_summaries', function (Blueprint $table) {
            $table->timestamp('lawyer_approved_at')->nullable()->after('approved_at');
            $table->foreignId('lawyer_approved_by')->nullable()->after('lawyer_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('lawyer_approved_by');
        });

        Schema::table('consults', function (Blueprint $table) {
            $table->timestamp('summary_lawyer_approved_at')->nullable()->after('summary_approved_by');
            $table->foreignId('summary_lawyer_approved_by')->nullable()->after('summary_lawyer_approved_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropConstrainedForeignId('summary_lawyer_approved_by');
            $table->dropColumn('summary_lawyer_approved_at');
        });

        Schema::table('ticket_summaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lawyer_approved_by');
            $table->dropColumn(['lawyer_approved_at', 'edited_at']);
        });
    }
};
