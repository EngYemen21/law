<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **المشاركون من الكادر حساباتٌ لا نصّ** (قرار المالك 2026-09-28).
 *
 * كانوا في `meetings.participants` أسماءً مفصولةً بفواصل تُطابَق بالاسم: يلتبس الاسمان المتشابهان،
 * ويقطع تغييرُ الاسم الربط، ويُشعَر المحامي المشارك «متاح في لوحتك» ولا يراه (قائمته المسندَ إليه وحده).
 *
 * النقل آمن: الاسم القديم يُربط بحسابه إن طابق **حساباً واحداً** من الكادر بالضبط، وما سواه يبقى نصّاً
 * في العمود القديم يُعرض ملاحظةً ولا يُخمَّن له حساب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['meeting_id', 'user_id']);
        });

        $staff = DB::table('users')->whereIn('role', ['lawyer', 'employee', 'admin'])->get(['id', 'name'])->groupBy('name');

        DB::table('meetings')->whereNotNull('participants')->where('participants', '!=', '')->orderBy('id')
            ->each(function ($meeting) use ($staff) {
                foreach (preg_split('/[،,]/u', (string) $meeting->participants, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
                    $name = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', trim($raw)));
                    $matches = $staff->get($name);

                    if ($matches !== null && $matches->count() === 1) {
                        DB::table('meeting_participants')->insertOrIgnore([
                            'meeting_id' => $meeting->id, 'user_id' => $matches->first()->id, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_participants');
    }
};
