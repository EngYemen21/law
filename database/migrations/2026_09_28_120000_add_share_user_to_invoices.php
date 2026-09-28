<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **صاحب نصيب الأتعاب يُجمَّد لحظة التحصيل** (`SettleInvoice`): كان النصيب يُنسب كلّه إلى المحامي
 * المسند *الآن*، فتغييرُ المحامي ينقل إلى الجديد ما حُصّل في عهد السابق — ويصير رصيد السابق سالباً
 * بما قبضه، ويُصرف المبلغ نفسه مرّتين.
 *
 * والفواتير المسدَّدة قبل هذا التغيير تُنسب إلى المحامي المسند حالياً — أقصى ما يُعرف عنها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('share_user_id')->nullable()->after('exec_id')->constrained('users')->nullOnDelete();
        });

        DB::table('invoices')->where('paid', true)->whereNotNull('case_id')->orderBy('id')->each(function ($inv) {
            DB::table('invoices')->where('id', $inv->id)->update([
                'share_user_id' => DB::table('cases')->where('id', $inv->case_id)->value('assigned_lawyer_id'),
            ]);
        });
        DB::table('invoices')->where('paid', true)->whereNotNull('exec_id')->orderBy('id')->each(function ($inv) {
            DB::table('invoices')->where('id', $inv->id)->update([
                'share_user_id' => DB::table('executions')->where('id', $inv->exec_id)->value('assigned_lawyer_id'),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('share_user_id');
        });
    }
};
