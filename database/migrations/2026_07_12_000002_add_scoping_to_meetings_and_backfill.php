<?php

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توحيد العزل: يمنح الاجتماعات عمودَي branch + assigned_lawyer_id (كالاستشارات) ليعزلها كل من
 * HTTP وقنوات البثّ بالقاعدة الموحّدة. ثم يملأ رجعياً assigned_lawyer_id/branch للاستشارات
 * والاجتماعات القائمة حتى لا تختفي عن المحامي/الفرع المخوّل بعد تفعيل العزل الصارم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_lawyer_id')->nullable();
            $table->string('branch')->nullable();
            $table->index(['assigned_lawyer_id']);
            $table->index(['branch']);
        });

        $this->backfillConsults();
        $this->backfillMeetings();
    }

    /** استشارة: المحامي/الفرع من التذكرة المصدر إن وُجدت، وإلا بمطابقة اسم المحامي بمستخدم. */
    private function backfillConsults(): void
    {
        Consult::whereNull('assigned_lawyer_id')->chunkById(200, function ($consults) {
            foreach ($consults as $consult) {
                $lawyerId = null;
                $branch = $consult->branch;

                if ($consult->ticket_id && ($ticket = Ticket::find($consult->ticket_id))) {
                    $lawyerId = $ticket->assigned_lawyer_id;
                    $branch = $branch ?: $ticket->branch;
                }
                if (! $lawyerId && $consult->lawyer) {
                    $user = User::where('role', Role::Lawyer)->where('name', $consult->lawyer)->first();
                    $lawyerId = $user?->id;
                    $branch = $branch ?: $user?->branch;
                }

                if ($lawyerId || $branch) {
                    $consult->forceFill(['assigned_lawyer_id' => $lawyerId, 'branch' => $branch])->save();
                }
            }
        });
    }

    /** اجتماع: المحامي/الفرع من منشئه (created_by بالاسم) إن كان محامياً، وإلا فرعه فقط. */
    private function backfillMeetings(): void
    {
        Meeting::whereNull('branch')->chunkById(200, function ($meetings) {
            foreach ($meetings as $meeting) {
                if (! $meeting->created_by) {
                    continue;
                }
                $creator = User::where('name', $meeting->created_by)->first();
                if (! $creator) {
                    continue;
                }
                $meeting->forceFill([
                    'branch' => $creator->branch,
                    'assigned_lawyer_id' => $creator->role === Role::Lawyer ? $creator->id : null,
                ])->save();
            }
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex(['assigned_lawyer_id']);
            $table->dropIndex(['branch']);
            $table->dropColumn(['assigned_lawyer_id', 'branch']);
        });
    }
};
