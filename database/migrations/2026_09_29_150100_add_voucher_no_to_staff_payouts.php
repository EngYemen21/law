<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **سند صرف لقيد صرف الموظّف** — دفترُ سندات الصرف واحد (`PV-YYYY-NNNNN`) للمصروفات وصرف
 * المستحقّات معاً. التعبئة الرجعيّة لكلّ القيود (والملغى يُطبع سنده «ملغى» ولا يُعاد رقمه) بترتيب
 * تاريخ الصرف، وقبل أيّ مصروف — جدولها جديد فارغ. `DB::table` لا نماذج التطبيق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_payouts', function (Blueprint $table) {
            $table->string('voucher_no', 20)->nullable()->unique()->after('id');
        });

        $serials = [];
        DB::table('staff_payouts')->orderBy('paid_at')->orderBy('id')->get(['id', 'paid_at'])
            ->each(function (object $p) use (&$serials) {
                $year = substr((string) $p->paid_at, 0, 4);
                $serials[$year] = ($serials[$year] ?? 0) + 1;
                DB::table('staff_payouts')->where('id', $p->id)
                    ->update(['voucher_no' => 'PV-'.$year.'-'.str_pad((string) $serials[$year], 5, '0', STR_PAD_LEFT)]);
            });
    }

    public function down(): void
    {
        Schema::table('staff_payouts', function (Blueprint $table) {
            $table->dropUnique(['voucher_no']);
            $table->dropColumn('voucher_no');
        });
    }
};
