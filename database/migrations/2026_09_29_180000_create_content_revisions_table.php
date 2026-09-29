<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سجلّ نسخ التحليلات والملخّصات** (طلب المالك 2026-09-29) — كلّ نصٍّ يولّده الذكاء الاصطناعي أو Zoom أو
 * القالب، وكلّ تعديلٍ بشريّ عليه، نسخةٌ كاملة بمصدرها وفاعلها وعنوانه ووقتها. كانت الكتابة فوق النصّ في
 * مكانه تُضيع الأصل وكلّ ما بينه وبين الأخير (ملخّص التذكرة، مسودّة اللائحة، دراسة التنفيذ، المحضر…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            // الملفّ المالك (التذكرة · الاستشارة · القضية · التنفيذ · الاجتماع) — لا صفّ النصّ نفسه
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_ref', 80)->nullable();
            $table->string('kind', 40);
            $table->unsignedInteger('version');
            // ai · zoom · template · human · baseline (ما قبل تفعيل السجلّ)
            $table->string('source', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_role', 16)->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('content');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'kind']);
            $table->unique(['subject_type', 'subject_id', 'kind', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_revisions');
    }
};
