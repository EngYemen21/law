<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            // القضية محوّلة من تذكرة استشارة + بيانات الإحالة والأتعاب
            $table->foreignId('ticket_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('assigned_lawyer')->nullable()->after('type');
            $table->string('department')->nullable()->after('assigned_lawyer');
            $table->unsignedInteger('fee')->nullable()->after('paid_text');       // قيمة الأتعاب (ر.س)
            $table->string('fee_status', 20)->default('none')->after('fee');        // none | pending_payment | paid
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
            $table->dropColumn(['assigned_lawyer', 'department', 'fee', 'fee_status']);
        });
    }
};
