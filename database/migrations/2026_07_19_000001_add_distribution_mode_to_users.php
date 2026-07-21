<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// وضع التوزيع للمحامين: 'auto' = يدخل في محرك التوزيع العادل، 'manual' = مستثنى (إسناد يدوي فقط)
// الطول 12 يستوعب القيمتين بأمان (auto=4, manual=6) + هامش للمستقبل.
// أُضيف index لأن الاستعلام where('distribution_mode','auto') يُنفَّذ على كل تذكرة في دفعة التوزيع.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('distribution_mode', 12)->default('auto')->after('department')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['distribution_mode']);
            $table->dropColumn('distribution_mode');
        });
    }
};
