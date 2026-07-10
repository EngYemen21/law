<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            // رحلة المعالجة (CONSULT_FLOW): استقبال → مراجعة الموظف → الفريق القانوني → اعتماد → إحالة للمحامي
            $table->string('type')->default('عام')->after('subject');          // تجاري / عمالي / تنفيذ / …
            $table->string('priority')->default('متوسطة')->after('type');      // عالية / متوسطة / عادية
            $table->string('employee')->nullable()->after('lawyer');           // الموظف المستلم
            $table->string('received_label')->nullable()->after('when_label'); // اليوم 09:14 ص
            $table->unsignedInteger('mins')->default(0)->after('total');       // دقائق المعالجة
            $table->boolean('ai_done')->default(false)->after('mins');         // اكتمل تحليل الفريق القانوني؟
            $table->string('ai_class')->nullable()->after('ai_done');          // تصنيف الاستشارة
            $table->text('ai_summary')->nullable()->after('ai_class');         // الملخص القانوني
            $table->string('ai_lawyer')->nullable()->after('ai_summary');      // المحامي المقترح
            $table->json('missing')->nullable()->after('ai_lawyer');           // المستندات الناقصة
            $table->json('audit')->nullable()->after('missing');               // سجل التدقيق
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn([
                'type', 'priority', 'employee', 'received_label', 'mins',
                'ai_done', 'ai_class', 'ai_summary', 'ai_lawyer', 'missing', 'audit',
            ]);
        });
    }
};
