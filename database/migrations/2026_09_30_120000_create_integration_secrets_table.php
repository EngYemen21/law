<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// مفاتيح الخدمات الخارجيّة التي تضبطها الإدارة العليا من الشاشة (ميسّر، Zoom، البريد، تقنيات، الذكاء) —
// **القيمة مشفّرة بمفتاح التطبيق** ولا تُحفظ في `settings` (عمودها نصٌّ بلا تعمية). المفتاح هو مسار الإعداد
// الذي تقرؤه الخدمة (`services.moyasar.secret_key`) فتسري القيمة بلا تعديلٍ في الخدمات.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_secrets', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_secrets');
    }
};
