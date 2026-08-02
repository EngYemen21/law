<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('proof_path')->nullable()->after('gateway_payment_id'); // إثبات التحويل اليدوي المرفوع
            $table->timestamp('proof_uploaded_at')->nullable()->after('proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['proof_path', 'proof_uploaded_at']);
        });
    }
};
