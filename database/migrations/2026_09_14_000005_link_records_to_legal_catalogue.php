<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **ربط السجلّات بكتالوج الأقسام** — المطابقة بالمعرّف لا بالنصّ.
 *
 * إضافةٌ لا تمسّ صفّاً قائماً ولا عموداً قائماً:
 *   - `lawyer_specialties`: للمحامي أكثر من تخصّص (قرار المالك 2026-09-14) — كان حقلاً نصّيّاً واحداً.
 *   - `users.covers_all_departments`: المحامي العامّ يغطّي كلّ قسم، ومنها ما يُضاف لاحقاً —
 *     ربطه بكلّ الأقسام صفّاً صفّاً كان سيُسقط كلّ قسمٍ جديد.
 *   - `legal_department_id` على التذاكر والقضايا والاستشارات، و`legal_service_id` على التذاكر.
 *
 * الأعمدة النصّيّة (`department` · `type` · `specialty`) تبقى كما هي: يقرؤها العرض في نحو ثلاثين
 * موضعاً، والمعرّف يُشتقّ منها عند الحفظ (LinksLegalDepartment) ويُملأ للقديم في الهجرة التالية.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'covers_all_departments')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('covers_all_departments')->default(false)->after('department');
            });
        }

        if (! Schema::hasTable('lawyer_specialties')) {
            Schema::create('lawyer_specialties', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('legal_department_id')->constrained('legal_departments')->restrictOnDelete();
                $table->timestamps();
                $table->primary(['user_id', 'legal_department_id']);
            });
        }

        $links = [
            'tickets' => ['legal_department_id' => 'legal_departments', 'legal_service_id' => 'legal_services'],
            'cases' => ['legal_department_id' => 'legal_departments'],
            'consults' => ['legal_department_id' => 'legal_departments'],
        ];

        foreach ($links as $tableName => $columns) {
            foreach ($columns as $column => $references) {
                if (Schema::hasColumn($tableName, $column)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($column, $references) {
                    $table->foreignId($column)->nullable()->constrained($references)->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['tickets' => ['legal_service_id', 'legal_department_id'], 'cases' => ['legal_department_id'], 'consults' => ['legal_department_id']] as $tableName => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId($column));
                }
            }
        }

        Schema::dropIfExists('lawyer_specialties');

        if (Schema::hasColumn('users', 'covers_all_departments')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('covers_all_departments'));
        }
    }
};
