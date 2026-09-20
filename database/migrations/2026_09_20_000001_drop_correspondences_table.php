<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **إسقاط وحدة «المخاطبات» نهائياً** (قرار المالك 2026-09-20).
 *
 * لماذا مهاجرةٌ جديدة لا حذفُ المهاجرة الأصليّة: المهاجرات تراكميّة وقد نُفِّذت على
 * الخادم فعلاً، فحذف ملفّها يترك جدولاً يتيماً في كلّ قاعدةٍ قائمة ويكسر `migrate`
 * على من نفّذها. الأصليّة (2026_07_31_000001) تبقى كما هي، وهذه تُسقط أثرها.
 *
 * ⚠️ مدمّرة: صفوف المخاطبات تضيع بلا مصدر اشتقاق. الرجوع الحقيقيّ = نسخة احتياطية.
 *
 * و`down()` يعيد البنية **بلا عمود `branch`** — لا سهواً بل مطابقةً للحالة التي كان
 * الجدول عليها لحظةَ هذا الإسقاط: مهاجرة «إسقاط الفرع» (2026_08_20_000002) حذفت
 * العمود من `correspondences` ضمن جداولها، و`down()` الخاصّ بها هو من يعيده. فلو
 * أعدناه هنا لأُنشئ مرّتين عند التراجع المتسلسل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('correspondences');
    }

    public function down(): void
    {
        Schema::create('correspondences', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();                 // MKH-YYYY-####
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // العميل
            $table->foreignId('assigned_lawyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lawyer')->nullable();                // اسم المحامي للعرض
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained()->nullOnDelete();

            $table->string('direction')->default('صادرة');       // صادرة | واردة
            $table->string('entity');                            // الجهة/المحكمة
            $table->string('subject');
            $table->string('channel')->nullable();               // قناة الإرسال (النظام الخارجيّ)
            $table->text('body')->nullable();

            $table->unsignedTinyInteger('stage')->default(0);    // 0..6 (CORR_FLOW)
            $table->string('status')->default('إنشاء المخاطبة'); // نصّ مشتقّ من المرحلة
            $table->string('tone')->default('b-grey');
            $table->string('date_label')->nullable();
            $table->string('due_label')->nullable();

            // النظام الخارجيّ (adapter)
            $table->string('ext_ref')->nullable();
            $table->string('ext_status')->nullable();
            $table->timestamp('ext_synced_at')->nullable();
            $table->text('reply_body')->nullable();

            // إفادة العميل
            $table->boolean('briefed')->default(false);
            $table->text('brief_note')->nullable();
            $table->boolean('brief_requested')->default(false);

            $table->json('audit')->nullable();                   // [{a, by, t}]
            $table->timestamps();
        });
    }
};
