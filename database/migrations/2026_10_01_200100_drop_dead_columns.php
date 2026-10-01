<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **أعمدةٌ ميّتة** (قرار المالك 2026-10-01، المجموعة ج) — لا كاتب لها ولا قيمة فيها:
 * - `meetings.is_up`: مهجور، و«القادم» يُشتقّ حيّاً (`Meeting::isUpcoming`).
 * - `meetings.before_items/during_items/after_items`: كاتبها الوحيد معلَّق (`MeetInvitation`)، وقارئها كودٌ معلَّق.
 * - `ai_runs.model_version`: في `fillable` وحده.
 * - `executions.payment_reminder_sent_at`: انتقل الختم إلى `invoices.reminder_sent_at`.
 *
 * الرجوع يعيدها فارغة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            $t->dropColumn(['is_up', 'before_items', 'during_items', 'after_items']);
        });
        Schema::table('ai_runs', fn (Blueprint $t) => $t->dropColumn('model_version'));
        Schema::table('executions', fn (Blueprint $t) => $t->dropColumn('payment_reminder_sent_at'));
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            $t->boolean('is_up')->default(false);
            $t->json('before_items')->nullable();
            $t->json('during_items')->nullable();
            $t->json('after_items')->nullable();
        });
        Schema::table('ai_runs', fn (Blueprint $t) => $t->string('model_version')->nullable());
        Schema::table('executions', fn (Blueprint $t) => $t->timestamp('payment_reminder_sent_at')->nullable());
    }
};
