<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * استهلاك التوكنات والكلفة التقديريّة — مطلب المرحلة P6.
 *
 * كلا المزوّدين يُعيد الاستهلاك في ردّه وكانت الشيفرة تُهمله، فلا سبيل لبناء
 * ميزانية ولا حصّة ولا تنبيه قفزة إنفاق.
 *
 * `estimated_cost` يبقى `null` حين لا سعر مُهيَّأ للنموذج — «لا كلفة معلومة» لا
 * «صفر». الصفر يقول إن النداء مجّانيّ وهو ادّعاء كاذب يُفسد كل مجموع يُبنى عليه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_runs')) {
            return;
        }

        Schema::table('ai_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_runs', 'input_tokens')) {
                $table->unsignedInteger('input_tokens')->nullable()->after('duration_ms');
            }
            if (! Schema::hasColumn('ai_runs', 'output_tokens')) {
                $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
            }
            if (! Schema::hasColumn('ai_runs', 'estimated_cost')) {
                // ستّ منازل: نداءٌ واحد قد يكلّف أجزاءً من الهللة، والتقريب المبكر
                // يُضيّع الفرق حين تُجمع آلاف النداءات
                $table->decimal('estimated_cost', 12, 6)->nullable()->after('output_tokens');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_runs')) {
            return;
        }

        Schema::table('ai_runs', function (Blueprint $table) {
            foreach (['input_tokens', 'output_tokens', 'estimated_cost'] as $column) {
                if (Schema::hasColumn('ai_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
