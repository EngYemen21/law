<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **كتالوج الأقسام والخدمات القانونيّة في قاعدة البيانات** (قرار المالك 2026-09-14).
 *
 * كانت القائمة مكتوبةً في خمسة أماكن متباعدة (نموذج التذكرة، وصفحة طلب الاستشارة، وأقسام
 * الطاقم، وتخصّصات الخادم، وتعليمات الذكاء) — فلا يتحقّق الخادم ممّا يُرسَل، و١٣ قسماً من ٢٩
 * لا تطابق أيّ تخصّص محامٍ. هذه الجداول المصدر الواحد.
 *
 * جداول جديدة لا تمسّ صفّاً قائماً:
 *   - `legal_departments` و`legal_services`: الإيقاف بـ`status` لا بالحذف — تذاكر قديمة تشير إليها.
 *   - `legal_catalogue_aliases`: كلّ اسمٍ قديم يُطابَق مع قسمٍ أو خدمة. `alias_folded` فريدٌ
 *     بعد توحيد الصياغة (SearchText::fold)، فلا يشير اسمٌ واحد إلى هدفين.
 *   - `staff_departments`: أقسام الموظّفين الإداريّة — مفهومٌ منفصل عن التخصّص القانونيّ.
 *
 * زرع الكتالوج المعتمد في هجرةٍ تالية (LegalCatalogueSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legal_departments')) {
            Schema::create('legal_departments', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name', 120)->unique();
                $table->unsignedSmallInteger('sort_order')->default(0)->index();
                $table->string('status', 12)->default('active')->index();
                $table->boolean('requires_specific_authority')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('legal_services')) {
            Schema::create('legal_services', function (Blueprint $table) {
                $table->id();
                $table->foreignId('legal_department_id')->constrained('legal_departments')->restrictOnDelete();
                $table->string('name', 160);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('status', 12)->default('active')->index();
                $table->timestamps();
                $table->unique(['legal_department_id', 'name']);
            });
        }

        if (! Schema::hasTable('legal_catalogue_aliases')) {
            Schema::create('legal_catalogue_aliases', function (Blueprint $table) {
                $table->id();
                $table->string('alias', 190);
                $table->string('alias_folded', 190)->unique();
                $table->foreignId('legal_department_id')->constrained('legal_departments')->restrictOnDelete();
                $table->foreignId('legal_service_id')->nullable()->constrained('legal_services')->restrictOnDelete();
                $table->string('source', 12)->default('seed'); // seed | rename
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('staff_departments')) {
            Schema::create('staff_departments', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120)->unique();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('status', 12)->default('active');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_departments');
        Schema::dropIfExists('legal_catalogue_aliases');
        Schema::dropIfExists('legal_services');
        Schema::dropIfExists('legal_departments');
    }
};
