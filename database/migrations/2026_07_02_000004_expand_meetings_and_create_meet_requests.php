<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // توسيع الاجتماع إلى FullMeeting (قبل/أثناء/بعد + محضر/ملخص + اعتماد + Zoom)
        Schema::table('meetings', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();                 // داخلي = بلا عميل
            $table->string('ref')->nullable()->unique()->after('id');           // M-1001
            $table->string('type')->default('اجتماع مع عميل')->after('title');  // من MEET_TYPES_FULL
            $table->string('client_name')->nullable()->after('type');          // عبدالله العتيبي / داخلي
            $table->string('status')->default('قادم')->after('when_label');    // قادم/جارٍ/منتهٍ/مؤجل/ملغى
            $table->string('priority')->default('عادية');
            $table->string('conf')->default('عادي');                            // سري/عادي
            $table->unsignedTinyInteger('attend')->default(0);                  // نسبة الحضور %
            $table->string('dur')->nullable();                                  // 60 دقيقة
            $table->string('approve')->default('بانتظار اعتماد الإدارة');
            $table->json('before_items')->nullable();
            $table->json('during_items')->nullable();
            $table->json('after_items')->nullable();
            $table->text('summary')->nullable();
            $table->boolean('sum_approved')->default(false);
            $table->text('minutes')->nullable();
            $table->string('participants')->nullable();
            $table->string('case_ref')->nullable();                             // ربط بقضية/استشارة
            $table->string('meet_id')->nullable();                              // Zoom
            $table->string('meet_link', 500)->nullable();
            $table->string('host_link', 1000)->nullable();
            $table->string('created_by')->nullable();
        });

        // دعوات/طلبات الاجتماعات (MR_FLOW): دعوة مُرسلة ← تأكيد العميل ← تنفيذ الجلسة ← اعتماد الإدارة
        Schema::create('meet_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();     // العميل المدعو
            $table->foreignId('meeting_id')->nullable()->constrained()->nullOnDelete(); // الاجتماع الناتج عن التأكيد
            $table->string('ref')->unique();                                    // MR-1042
            $table->string('service');                                          // نزاع تجاري
            $table->string('type');                                             // استشارة مرئية/حضورية/هاتفية
            $table->string('case_ref')->nullable();                             // قضية/استشارة مرتبطة
            $table->string('day');
            $table->string('time');
            $table->string('sent_by');                                          // منيرة الحربي (خدمة العملاء)
            $table->unsignedTinyInteger('stage')->default(0);                   // 0..3
            $table->string('meet_id')->nullable();                              // Zoom بعد التأكيد
            $table->string('meet_link', 500)->nullable();
            $table->string('host_link', 1000)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meet_requests');
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn([
                'ref', 'type', 'client_name', 'status', 'priority', 'conf', 'attend', 'dur',
                'approve', 'before_items', 'during_items', 'after_items', 'summary', 'sum_approved',
                'minutes', 'participants', 'case_ref', 'meet_id', 'meet_link', 'host_link', 'created_by',
            ]);
        });
    }
};
