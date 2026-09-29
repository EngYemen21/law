<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **سند القبض** (المرحلة أ من §١٢ في `docs/finance-plan.md`، طلب المالك 2026-09-29).
 *
 * دفتر `payments` كان يعرف المبلغ والبوّابة ولا يعرف ما يُطبع على سند: رقمه، وطريقة القبض (نقد أو
 * تحويل أو بوّابة)، وتاريخ القبض الفعليّ، ومن قبضه. أعمدةٌ مضافة لا يُحذف معها شيء.
 *
 * **التعبئة الرجعيّة للمدفوع وحده** (`status = paid`): تاريخ القبض من التسوية وإلّا الإنشاء، والطريقة
 * «بوّابة» لصفوف ميسّر وتبقى فارغةً لليدويّ القديم (لم يُسجَّل أنقداً كان أم تحويلاً — لا يُخمَّن)،
 * ورقمٌ متسلسل لكلّ سنة بترتيب القبض. والكتابة بـ`DB::table` لا بنماذج التطبيق — فلا تنكسر المهاجرة
 * إن تغيّر النموذج لاحقاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_no', 20)->nullable()->unique()->after('gateway_payment_id');
            $table->string('method', 16)->nullable()->after('gateway');
            $table->timestamp('received_at')->nullable()->after('reconciled_at');
            $table->foreignId('actor_id')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable()->after('actor_id');
        });

        $serials = [];
        DB::table('payments')->where('status', 'paid')
            ->orderByRaw('COALESCE(reconciled_at, created_at)')->orderBy('id')
            ->get(['id', 'gateway', 'reconciled_at', 'created_at'])
            ->each(function (object $p) use (&$serials) {
                $at = $p->reconciled_at ?? $p->created_at;
                $year = substr((string) $at, 0, 4);
                $serials[$year] = ($serials[$year] ?? 0) + 1;

                DB::table('payments')->where('id', $p->id)->update([
                    'received_at' => $at,
                    'method' => $p->gateway === 'manual' ? null : 'gateway',
                    'receipt_no' => 'RV-'.$year.'-'.str_pad((string) $serials[$year], 5, '0', STR_PAD_LEFT),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actor_id');
            $table->dropUnique(['receipt_no']);
            $table->dropColumn(['receipt_no', 'method', 'received_at', 'note']);
        });
    }
};
