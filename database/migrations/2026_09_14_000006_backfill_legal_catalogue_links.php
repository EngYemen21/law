<?php

use App\Support\CatalogueBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * **ملء روابط الكتالوج للسجلّات القائمة** — يملأ الفارغ فقط، ولا يمسّ النصوص.
 *
 * المطابقة صارمة (اسم قسم، أو اسم بديل، أو اسم خدمةٍ فريد) — ما لا يُطابَق يبقى بلا معرّف
 * ويستمرّ الإسناد في قراءته نصّاً كما كان. لمراجعة ما لم يُطابَق بعد النشر:
 * `php artisan catalogue:backfill --dry-run`.
 */
return new class extends Migration
{
    public function up(): void
    {
        CatalogueBackfill::run();
    }

    /** لا شيء: الأعمدة تُحذف في تراجع الهجرة السابقة. */
    public function down(): void {}
};
