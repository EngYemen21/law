<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **عنوانُ IP لمُرسِل كلّ رسالة محادثة** (طلب المالك 2026-09-25) — للطاقم وحده، لا للعميل.
 *
 * جداول المحادثة ثلاثة لا غير: التذكرة والقضيّة والتنفيذ. والعمود يُملأ في النموذج نفسه
 * (`App\Models\Concerns\RecordsSenderIp`) لا في المتحكّمات، فلا تفوته رسالةٌ من مسارٍ جديد.
 *
 * - `string(45)`: أطول تمثيلٍ نصّيّ لعنوان IPv6 (‏IPv4 المضمَّن فيه) يبلغ ٤٥ حرفاً.
 * - `nullable`: رسائل النظام والمساعد والطوابير لا مُرسِل بشريّ لها فلا عنوان — والرسائل
 *   القديمة تبقى بلا عنوان صادقةً بدل عنوانٍ مختلَق.
 */
return new class extends Migration
{
    private const TABLES = ['ticket_messages', 'case_messages', 'execution_messages'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('sender_ip', 45)->nullable()->after('time_label');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('sender_ip');
            });
        }
    }
};
