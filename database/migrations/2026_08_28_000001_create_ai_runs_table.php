<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ موحَّد لكل مخرج ذكاء اصطناعيّ — يجيب عن: من أنشأه؟ بأي نموذج وإصدار Prompt؟
 * أهو تحليل فعليّ أم قالب احتياطيّ؟ ما مدى ثقته؟ وهل راجعه إنسان؟
 *
 * كانت حالة الذكاء مبعثرة على أعمدة `ai_*` في أربعة نماذج بلا مصدر ولا نموذج ولا تتبّع،
 * فيستحيل تدقيق مخرج بعد وقوعه. النمط والفهارس تتبع `audit_logs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();

            // triage / document / consult / draft / meeting / execution
            $table->string('task_type');

            // الملفّ المرتبط — بلا تكرار السياق الحسّاس داخل السجلّ
            $table->string('entity_type')->nullable(); // Model class
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_ref')->nullable(); // EX-2026-11 / SB-2026-7003

            // مصدر النتيجة — App\Enums\AiSource
            $table->string('source');
            // queued / running / completed / failed / needs_review / approved
            $table->string('status')->default('completed');

            // null حين لا تنتج المهمّة ثقة موثوقة — لا تُختلق قيمة أبداً
            $table->unsignedTinyInteger('confidence')->nullable();

            $table->string('model')->nullable();
            $table->string('model_version')->nullable();
            $table->string('prompt_version')->nullable();

            // يربط الطلب بالـJob بالمخرج
            $table->uuid('trace_id')->nullable();

            // سبب فشل معياريّ بلا أسرار ولا نصوص مستندات
            $table->string('failure_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // دليل الاعتماد البشريّ
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['task_type', 'created_at']);
            $table->index(['source', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('trace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
