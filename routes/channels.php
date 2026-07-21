<?php

use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ChannelAccess;
use Illuminate\Support\Facades\Broadcast;

/**
 * تفويض قنوات البثّ الخاصة بقاعدة موحّدة (App\Support\ChannelAccess): العميل المالك، أو الإدارة،
 * أو المحامي المسند، أو موظف نفس الفرع فقط. يسدّ التسرّب العابر للفروع الذي كان يصرّح لأي موظف.
 */

// قناة التذكرة — العميل صاحبها أو موظف مخوّل (فرعه/إسناده)
Broadcast::channel('ticket.{ticketId}', function (User $user, int $ticketId) {
    $ticket = Ticket::find($ticketId);

    return $ticket ? ChannelAccess::ownerOrStaff($user, $ticket) : false;
});

// قناة الملاحظات الداخلية — الموظف المخوّل فقط (لا العميل، ولا موظف فرع آخر)
Broadcast::channel('ticket.{ticketId}.staff', function (User $user, int $ticketId) {
    $ticket = Ticket::find($ticketId);

    return $ticket ? ChannelAccess::staffCanSee($user, $ticket) : false;
});

// قناة القضية — العميل صاحبها أو موظف مخوّل
Broadcast::channel('case.{caseId}', function (User $user, int $caseId) {
    $case = LegalCase::find($caseId);

    return $case ? ChannelAccess::ownerOrStaff($user, $case) : false;
});

// قناة طلب التنفيذ — العميل صاحبه أو موظف مخوّل
Broadcast::channel('exec.{execId}', function (User $user, int $execId) {
    $exec = Execution::find($execId);

    return $exec ? ChannelAccess::ownerOrStaff($user, $exec) : false;
});

// قناة الاستشارة — العميل صاحبها أو موظف مخوّل (حالة الجلسة والملخص)
Broadcast::channel('consult.{consultId}', function (User $user, int $consultId) {
    $consult = Consult::find($consultId);

    return $consult ? ChannelAccess::ownerOrStaff($user, $consult) : false;
});

// قناة الاجتماع — العميل صاحبه أو موظف مخوّل (الحالة والاعتماد والمحضر)
Broadcast::channel('meeting.{meetingId}', function (User $user, int $meetingId) {
    $meeting = Meeting::find($meetingId);

    return $meeting ? ChannelAccess::ownerOrStaff($user, $meeting) : false;
});
