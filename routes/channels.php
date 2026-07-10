<?php

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// قناة التذكرة — العميل صاحبها أو أي موظف/محامي/إدارة
Broadcast::channel('ticket.{ticketId}', function (User $user, int $ticketId) {
    $ticket = Ticket::find($ticketId);
    if (! $ticket) {
        return false;
    }

    return $ticket->user_id === $user->id || $user->role !== Role::Client;
});

// قناة الملاحظات الداخلية — الموظفون فقط (لا العميل)
Broadcast::channel('ticket.{ticketId}.staff', function (User $user, int $ticketId) {
    return $user->role !== Role::Client && Ticket::whereKey($ticketId)->exists();
});

// قناة القضية — العميل صاحبها أو أي موظف/محامي/إدارة
Broadcast::channel('case.{caseId}', function (User $user, int $caseId) {
    $case = LegalCase::find($caseId);
    if (! $case) {
        return false;
    }

    return $case->user_id === $user->id || $user->role !== Role::Client;
});

// قناة طلب التنفيذ — العميل صاحبه أو أي موظف/محامي/إدارة
Broadcast::channel('exec.{execId}', function (User $user, int $execId) {
    $exec = Execution::find($execId);
    if (! $exec) {
        return false;
    }

    return $exec->user_id === $user->id || $user->role !== Role::Client;
});

// قناة الاستشارة — العميل صاحبها أو أي موظف/محامي/إدارة (حالة الجلسة والملخص)
Broadcast::channel('consult.{consultId}', function (User $user, int $consultId) {
    $consult = Consult::find($consultId);
    if (! $consult) {
        return false;
    }

    return $consult->user_id === $user->id || $user->role !== Role::Client;
});

// قناة الاجتماع — العميل صاحبه أو أي موظف/محامي/إدارة (الحالة والاعتماد والمحضر)
Broadcast::channel('meeting.{meetingId}', function (User $user, int $meetingId) {
    $meeting = Meeting::find($meetingId);
    if (! $meeting) {
        return false;
    }

    return $meeting->user_id === $user->id || $user->role !== Role::Client;
});
