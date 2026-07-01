<?php

use App\Enums\Role;
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
    $case = \App\Models\LegalCase::find($caseId);
    if (! $case) {
        return false;
    }

    return $case->user_id === $user->id || $user->role !== Role::Client;
});
