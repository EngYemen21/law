<?php

use Database\Seeders\LegalCatalogueSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * **زرع الكتالوج المعتمد في الهجرة لا في الزارع وحده.**
 *
 * الاختبارات لا تشغّل الزارعات، والإسناد والتحقّق من نموذج التذكرة يحتاجان الكتالوج في كلّ
 * اختبار وفي كلّ بيئة تُهاجَر — والهجرة تضمن وجوده. الزارع إضافيٌّ (ما ليس موجوداً فقط)، فتشغيله
 * لاحقاً من `DatabaseSeeder` لا يُكرّر ولا يكتب فوق تعديلات الإدارة.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(LegalCatalogueSeeder::class)->run();
    }

    /**
     * لا شيء: الصفوف تُحذف مع جداولها في تراجع الهجرة السابقة، وحذفها هنا منفرداً
     * يكسر تذاكر تشير إليها.
     */
    public function down(): void {}
};
