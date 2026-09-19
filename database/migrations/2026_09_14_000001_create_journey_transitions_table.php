<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ انتقالات الرحلة (خطّة إعادة البناء 2026-09-14، الدفعة ١).
 *
 * جدولٌ جديد لا يمسّ صفّاً قائماً. كلُّ انتقالٍ ينفّذه `Workflow` يُكتب هنا داخل معاملته،
 * فلا يوجد انتقالٌ بلا أثر ولا أثرٌ لانتقالٍ تراجع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journey_transitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_ref', 60)->nullable();
            $table->string('transition', 80);
            $table->string('from_state', 80)->nullable();
            $table->string('to_state', 80);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('transition');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_transitions');
    }
};
