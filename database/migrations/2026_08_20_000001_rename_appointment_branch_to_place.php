<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فصل الدلالة: عمود appointments.branch لم يكن فرعاً تنظيمياً قط، بل «مكان/قناة الجلسة»
 * (اجتماع إلكتروني | مكالمة هاتفية | الرياض — حي العليا) الآتي من ConsultBooking::MAP.
 * تسميته باسم الفرع كانت تخلط بينه وبين محور عزل الرؤية، وتُنتج مقارنات فاسدة
 * (DashboardController كان يقارنه بفرع الموظف التنظيمي فلا يطابق شيئاً أبداً).
 * إعادة تسمية خالصة: لا تغيير نوع ولا قيود (NOT NULL محفوظ)، وقابلة للعكس بلا فقد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->renameColumn('branch', 'place');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->renameColumn('place', 'branch');
        });
    }
};
