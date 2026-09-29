<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **فترات إيقاف الموظّف** — يكتبها زرّ الإيقاف/التفعيل (`Admin\StaffController::toggle`). كان الإيقاف
 * يغيّر `users.status` وحده بلا تاريخ، فيبقى الراتب يُحسب للموظّف الموقوف (`Finance\StaffEarnings`).
 *
 * الموقوف حالياً تُفتح له فترةٌ من آخر تعديلٍ على صفّه — أقرب تاريخٍ معروف لإيقافه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable(); // null = ما زال موقوفاً
            $table->timestamps();

            $table->index(['user_id', 'starts_on']);
        });

        DB::table('users')->where('status', 'suspended')->whereIn('role', ['employee', 'lawyer'])->orderBy('id')->each(function ($u) {
            DB::table('staff_suspensions')->insert([
                'user_id' => $u->id,
                'starts_on' => substr((string) ($u->updated_at ?? now()), 0, 10),
                'ends_on' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_suspensions');
    }
};
