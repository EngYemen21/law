<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **لا يُحذف مستخدمٌ له سجلّاتٌ ماليّة أو قانونيّة** (قرار المالك 2026-10-01، المجموعة ج).
 *
 * كانت `user_id` في هذه الجداول `ON DELETE CASCADE`: حذفُ عميلٍ من القاعدة يمحو تذاكره وقضاياه
 * وملفّات تنفيذه واستشاراته **وفواتيره**، وتبقى مدفوعاتها يتيمة (`payments.invoice_id` SET NULL).
 * لا مسار في التطبيق يحذف مستخدماً (الإيقاف هو الطريق)، فلا يتغيّر الاستخدام — والقيد يحرس
 * الحذف اليدويّ وأيّ ميزةٍ قادمة. أبناء الكيان (رسائل التذكرة، جلسات القضيّة…) تبقى تتبع أباها.
 */
return new class extends Migration
{
    private const TABLES = ['invoices', 'tickets', 'cases', 'executions', 'consults', 'staff_payouts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['user_id']);
                $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['user_id']);
                $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
