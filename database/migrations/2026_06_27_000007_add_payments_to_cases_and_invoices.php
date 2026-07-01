<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('pay_plan', 10)->nullable()->after('fee_status');           // full | install
            $table->unsignedTinyInteger('installments_total')->default(1)->after('pay_plan');
            $table->unsignedTinyInteger('installments_paid')->default(0)->after('installments_total');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('case_id')->nullable()->after('user_id')->constrained('cases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('case_id');
        });
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn(['pay_plan', 'installments_total', 'installments_paid']);
        });
    }
};
