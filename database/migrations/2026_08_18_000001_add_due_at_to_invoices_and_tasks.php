<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * استحقاق حقيقي للفواتير والمهام — كانت «due_label/due» نصوصاً جامدة («خلال 3 أيام» للأبد)
 * فلا يمكن اشتقاق «متأخرة» ولا فرزٌ بالاستحقاق. النصوص تبقى للعرض والتاريخ هو مصدر الحساب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('due_at')->nullable()->after('due_label');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('due_at')->nullable()->after('due');
            $table->timestamp('completed_at')->nullable()->after('due_at');
        });

        // backfill تقريبي لغير المدفوعة من نص الاستحقاق وقت الإصدار: «خلال 14 يوماً» أو «خلال 3 أيام» (وإلا أسبوع)
        foreach (DB::table('invoices')->where('paid', false)->get(['id', 'created_at', 'due_label']) as $inv) {
            $label = (string) ($inv->due_label ?? '');
            $days = str_contains($label, '14') ? 14 : (str_contains($label, '3') ? 3 : 7);
            DB::table('invoices')->where('id', $inv->id)->update([
                'due_at' => Carbon::parse($inv->created_at ?? now())->addDays($days)->toDateString(),
            ]);
        }

        // المهام المنجزة تُعلَّم مكتملة تقريبياً بوقت آخر تحديث لها
        DB::table('tasks')->where('status', 'منجزة')->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('due_at');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['due_at', 'completed_at']);
        });
    }
};
