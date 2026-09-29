<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('consults') && ! Schema::hasColumn('consults', 'google_event_id')) {
            Schema::table('consults', function (Blueprint $table) {
                $table->string('google_event_id')->nullable();
            });
        }

        if (Schema::hasTable('meetings') && ! Schema::hasColumn('meetings', 'google_event_id')) {
            Schema::table('meetings', function (Blueprint $table) {
                $table->string('google_event_id')->nullable();
            });
        }

        if (Schema::hasTable('appointments') && ! Schema::hasColumn('appointments', 'google_event_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->string('google_event_id')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('consults') && Schema::hasColumn('consults', 'google_event_id')) {
            Schema::table('consults', function (Blueprint $table) {
                $table->dropColumn('google_event_id');
            });
        }

        if (Schema::hasTable('meetings') && Schema::hasColumn('meetings', 'google_event_id')) {
            Schema::table('meetings', function (Blueprint $table) {
                $table->dropColumn('google_event_id');
            });
        }

        if (Schema::hasTable('appointments') && Schema::hasColumn('appointments', 'google_event_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropColumn('google_event_id');
            });
        }
    }
};
