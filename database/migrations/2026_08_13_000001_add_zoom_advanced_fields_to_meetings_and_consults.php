<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('zoom_uuid')->nullable()->after('meet_id');
            $table->text('zoom_share_url')->nullable()->after('recording_url');
            $table->text('zoom_audio_url')->nullable()->after('zoom_share_url');
            $table->json('zoom_participants_log')->nullable()->after('participants');
            $table->json('zoom_ai_next_steps')->nullable()->after('zoom_summary');
        });

        Schema::table('consults', function (Blueprint $table) {
            $table->string('zoom_uuid')->nullable()->after('meet_id');
            $table->text('zoom_share_url')->nullable()->after('recording_url');
            $table->text('zoom_audio_url')->nullable()->after('zoom_share_url');
            $table->json('zoom_participants_log')->nullable()->after('transcript_path');
            $table->json('zoom_ai_next_steps')->nullable()->after('zoom_summary_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn([
                'zoom_uuid',
                'zoom_share_url',
                'zoom_audio_url',
                'zoom_participants_log',
                'zoom_ai_next_steps',
            ]);
        });

        Schema::table('consults', function (Blueprint $table) {
            $table->dropColumn([
                'zoom_uuid',
                'zoom_share_url',
                'zoom_audio_url',
                'zoom_participants_log',
                'zoom_ai_next_steps',
            ]);
        });
    }
};
