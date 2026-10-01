<?php

use App\Support\SettingsRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// **بيانات البائع مجمَّدةً على الفاتورة يوم إصدارها** — كانت الفاتورة الضريبيّة ورمزها (ZATCA) يقرآن اسم المكتب ورقمه
// الضريبيّ لحظة العرض، فتعديلهما يغيّر فواتير صدرت (تدقيق الإعدادات 2026-09-30). null = لم تُجمَّد (قديمة)،
// و'' = صدرت والمكتب بلا رقمٍ ضريبيّ. الصادر قبل هذه الهجرة يُجمَّد بالقيم الحاليّة — أقرب ما يُعرف.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('seller_name', 200)->nullable()->after('vat_amount');
            $table->string('seller_vat_number', 30)->nullable()->after('seller_name');
        });

        // من السجلّ (بافتراضاته) لا من الجدول الخامّ — اسمٌ لم يُضبط قطّ يأخذ افتراضه المعلَن
        DB::table('invoices')->whereNotNull('issued_at')->update([
            'seller_name' => SettingsRegistry::str('office_name'),
            'seller_vat_number' => SettingsRegistry::str('office_vat_number'),
        ]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['seller_name', 'seller_vat_number']);
        });
    }
};
