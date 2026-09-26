<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **من كتب الرسالة، ومن يتولّى المحادثة.** (طلب المالك 2026-09-25)
 *
 * - `sender_id` على الرسائل: كانت تحمل اسمَ المُرسِل **نصّاً منسوخاً** فقط — يضيع إن تغيّر الاسم
 *   أو حُذف الحساب أو تشابه اسمان، ولا يُعرف منه دورُ الكاتب (ردُّ الإدارة على التذكرة يُخزَّن
 *   `who='lawyer'`، وفي التنفيذ باسمٍ ثابت «الإدارة العليا»).
 * - `handler_id` على الملفّات الثلاثة: الموظّف المسؤول عن محادثتها الآن. وسجلّ من تولّاها ومتى
 *   في `journey_transitions` (`conversation.taken_over`) لا في جدولٍ موازٍ.
 *
 * **ولا تعبئة للقديم:** مطابقة الاسم النصّيّ بحسابٍ تخمين (أسماءٌ تتكرّر، وتسمياتٌ كـ«الإدارة»).
 */
return new class extends Migration
{
    private const MESSAGES = ['ticket_messages', 'case_messages', 'execution_messages'];

    private const CONVERSATIONS = ['tickets', 'cases', 'executions'];

    public function up(): void
    {
        foreach (self::MESSAGES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('sender_id')->nullable()->after('who')->constrained('users')->nullOnDelete();
            });
        }

        foreach (self::CONVERSATIONS as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('handler_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::CONVERSATIONS as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('handler_id'));
        }

        foreach (self::MESSAGES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('sender_id'));
        }
    }
};
