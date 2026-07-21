<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// حقل يتتبّع اعتماد الموظف لملخص المستند قبل إرساله للعميل
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_documents', function (Blueprint $table) {
            // null = بانتظار الاعتماد | true = معتمد وأُرسل للعميل
            $table->boolean('summary_approved')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_documents', function (Blueprint $table) {
            $table->dropColumn('summary_approved');
        });
    }
};
