<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // الدور: client | employee | lawyer | admin
            $table->string('role', 20)->default('client')->after('email');
            // اسم العرض المختصر (للأفاتار) مثل "ع م"
            $table->string('avatar_initials', 8)->nullable()->after('role');
            // مسمى وظيفي / لقب اختياري مثل "أ."
            $table->string('title', 40)->nullable()->after('avatar_initials');
            $table->string('phone', 30)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'avatar_initials', 'title', 'phone']);
        });
    }
};
