<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->string('meet_id')->nullable()->after('phone');    // معرّف اجتماع Zoom
            $table->string('meet_link', 500)->nullable()->after('meet_id');   // رابط انضمام العميل (join_url)
            $table->string('host_link', 1000)->nullable()->after('meet_link'); // رابط المضيف للمكتب (start_url)
        });
    }

    public function down(): void
    {
        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn(['meet_id', 'meet_link', 'host_link']);
        });
    }
};
