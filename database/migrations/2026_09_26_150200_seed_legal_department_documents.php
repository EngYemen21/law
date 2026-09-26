<?php

use App\Support\DepartmentDocumentsSeed;
use Illuminate\Database\Migrations\Migration;

/**
 * **زرع قوائم مستندات الأقسام** من القوائم السبع عشرة القديمة (`database/data/legal_department_documents.php`).
 *
 * لماذا في هجرة لا في الأمر وحده (نظير `seed_legal_catalogue`): حذفُ `ServiceDocs` يُسقط القوائم من
 * الشيفرة في النشر نفسه؛ فلو تُرك الزرع لأمرٍ يُنسى لطُلبت القائمة العامّة من كلّ تذكرة، وتراجع ما
 * كان يُطلب لأنواعٍ قديمة تطابق قسماً. والأمر `catalogue:seed-documents --dry-run` باقٍ للمعاينة والإعادة.
 *
 * يكتب في قسمٍ لا قائمة له فقط — فتكراره آمن ولا يمسّ ما حرّرته الإدارة. و`down()` لا يحذف شيئاً:
 * الجدول نفسه تُسقطه هجرته، وما حرّرته الإدارة بعد الزرع لا يُميَّز عن المزروع.
 */
return new class extends Migration
{
    public function up(): void
    {
        DepartmentDocumentsSeed::run();
    }

    public function down(): void
    {
        //
    }
};
