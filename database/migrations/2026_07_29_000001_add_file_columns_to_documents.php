<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('path')->nullable()->after('meta');   // المسار الفعلي على قرص التخزين (للمرفوعة)
            $table->string('mime')->nullable()->after('path');
            $table->unsignedInteger('size')->nullable()->after('mime');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['path', 'mime', 'size']);
        });
    }
};
