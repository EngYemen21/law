<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// دفتر مدفوعات ميسّر (ledger): صفّ لكلّ حدث دفع يصل من البوّابة — تدقيق ومطابقة محاسبيّة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('gateway')->default('moyasar');              // بوّابة الدفع
            $table->string('gateway_invoice_id')->nullable();           // = invoices.gateway_ref (payment.invoice_id)
            $table->string('gateway_payment_id')->nullable()->unique(); // معرّف دفعة ميسّر — idempotency على مستوى الدفتر
            $table->string('status');                                   // paid | failed | ... (كما وردت من ميسّر)
            $table->integer('amount');                                 // بالهللة (كما في invoices)
            $table->string('currency', 3)->default('SAR');
            $table->string('source_channel', 16);                      // webhook | callback | backfill
            $table->json('raw')->nullable();                           // لقطة حمولة الدفعة الكاملة (تدقيق)
            $table->timestamp('reconciled_at')->nullable();            // متى طُبِّقت على المجال (null = سُجِّلت ولم تُسوَّ)
            $table->timestamps();
            $table->index(['invoice_id', 'status']);
        });

        // Backfill: كلّ فاتورة سُوّيت سابقاً (لها gateway_payment_id) تُحوَّل لصفّ دفتر واحد — تدقيق تاريخيّ.
        DB::table('invoices')
            ->whereNotNull('gateway_payment_id')
            ->where('gateway_payment_id', '!=', '')
            ->orderBy('id')
            ->each(function ($invoice) {
                $exists = DB::table('payments')->where('gateway_payment_id', $invoice->gateway_payment_id)->exists();
                if ($exists) {
                    return;
                }
                DB::table('payments')->insert([
                    'invoice_id' => $invoice->id,
                    'gateway' => 'moyasar',
                    'gateway_invoice_id' => $invoice->gateway_ref ?: null,
                    'gateway_payment_id' => $invoice->gateway_payment_id,
                    'status' => 'paid',
                    'amount' => (int) round($invoice->amount * 100),
                    'currency' => 'SAR',
                    'source_channel' => 'backfill',
                    'raw' => null,
                    'reconciled_at' => $invoice->updated_at,
                    'created_at' => $invoice->updated_at,
                    'updated_at' => $invoice->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
