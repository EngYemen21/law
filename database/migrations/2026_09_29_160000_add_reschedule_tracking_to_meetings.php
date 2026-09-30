<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **طلب العميل تغيير موعد الاجتماع يُحفظ، وإعادة الجدولة تُعدّ** (قرار المالك 2026-09-29) — كنظيرَيهما في
 * الاستشارة (`consults.reschedule_requested_at` · `reschedule_count`): الطلب لا يتكرّر وهو قيد المعالجة،
 * وما بعد سقف `meeting_reschedule_limit` للإدارة العليا وحدها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('reschedule_requested_at')->nullable()->after('starts_at');
            $table->unsignedSmallInteger('reschedule_count')->default(0)->after('reschedule_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['reschedule_requested_at', 'reschedule_count']);
        });
    }
};
