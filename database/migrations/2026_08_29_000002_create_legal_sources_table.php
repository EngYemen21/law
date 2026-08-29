<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قاعدة المعرفة القانونيّة المعتمدة — مصادر الاستشهاد وبياناتها الحاكمة.
 *
 * **لا يُملأ هذا الجدول برمجياً ولا من ذاكرة نموذج.** كل صفّ يلزمه: من يملك المصدر،
 * ومتى بدأ سريانه ومتى انتهى، وأي إصدار، وأي ولاية، وما نطاق استعماله، ومتى راجعه
 * محامٍ. مادّة بلا هذه البيانات ليست مصدراً — هي نصّ مجهول لا يصلح للاستشهاد.
 *
 * الغرض التقنيّ: أن يصير `source_id` الذي يدّعيه النموذج **قابلاً للمطابقة خادمياً**،
 * فلا تمرّ مادّة متخيَّلة مهما بدت مقنعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_sources', function (Blueprint $table) {
            $table->id();

            // المعرّف المستقرّ المُمرَّر للنموذج والمُستشهَد به (LS-…)
            $table->string('ref')->unique();

            $table->string('system_name');           // نظام المعاملات المدنية…
            $table->string('article_no')->nullable(); // رقم المادة
            $table->string('title')->nullable();
            $table->text('text');                     // نصّ المقطع

            $table->string('jurisdiction')->default('السعودية');
            $table->string('domain')->nullable();     // تجاري/عمالي/تنفيذ… (null = عامّ)
            $table->string('version')->nullable();

            // السريان: يُرشَّح به بتاريخ الواقعة لا بتاريخ اليوم
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            // الحوكمة — بلا مالك ومراجعة لا يصير النصّ مصدراً
            $table->string('source_owner');
            $table->string('source_url')->nullable();
            $table->date('legal_review_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usage_scope')->nullable(); // مسودات داخليّة / عرض على العميل…

            // معتمد / مسودة / موقوف — غير المعتمد لا يُستشهد به
            $table->string('status')->default('مسودة');

            $table->timestamps();

            $table->index(['status', 'jurisdiction', 'domain']);
            $table->index(['effective_from', 'effective_to']);
            $table->index('system_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_sources');
    }
};
