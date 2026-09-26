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
 * أو المحامي المسند، أو موظف المكتب. يمنع اشتراك عميل بقناة داخلية أو محامٍ بسجلٍّ غير مسنَد إليه.
 */

// قناة إشعارات المستخدم — يشترك المستخدم بقناته وحده (لا يرى إشعارات غيره)
Broadcast::channel('notifications.{userId}', fn (User $user, int $userId) => (int) $userId === (int) $user->id);

// قناة التذكرة — العميل صاحبها أو موظف المكتب أو المحامي المسنَد
Broadcast::channel('ticket.{ticketId}', function (User $user, int $ticketId) {
    $ticket = Ticket::find($ticketId);

    return $ticket ? ChannelAccess::ownerOrStaff($user, $ticket) : false;
});

// قناة الملاحظات الداخلية — الموظفون فقط (لا العميل، ولا محامٍ غير مسنَد)
Broadcast::channel('ticket.{ticketId}.staff', function (User $user, int $ticketId) {
    $ticket = Ticket::find($ticketId);

    return $ticket ? ChannelAccess::staffCanSee($user, $ticket) : false;
});

// حضور الموظفين داخل المحادثة (presence) — يُنبّه الداخل الجديد أن زميلاً يتحدث مع العميل
// فيتفادى الردّ المزدوج. تُعيد بيانات العضو للعرض، أو null لمنع الانضمام (ومنه العميل).
Broadcast::channel('ticket.{ticketId}.presence', function (User $user, int $ticketId) {
    $ticket = Ticket::find($ticketId);

    return $ticket ? ChannelAccess::presenceMember($user, $ticket) : null;
});

// قناة القضية — العميل صاحبها أو موظف مخوّل
Broadcast::channel('case.{caseId}', function (User $user, int $caseId) {
    $case = LegalCase::find($caseId);

    return $case ? ChannelAccess::ownerOrStaff($user, $case) : false;
});

// ملاحظات القضية الداخلية — الطاقم المخوّل وحده (`RecordsSender::broadcastChannelName`)
Broadcast::channel('case.{caseId}.staff', function (User $user, int $caseId) {
    $case = LegalCase::find($caseId);

    return $case ? ChannelAccess::staffCanSee($user, $case) : false;
});

// قناة طلب التنفيذ — العميل صاحبه أو موظف مخوّل
Broadcast::channel('exec.{execId}', function (User $user, int $execId) {
    $exec = Execution::find($execId);

    return $exec ? ChannelAccess::ownerOrStaff($user, $exec) : false;
});

// ملاحظات التنفيذ الداخلية — الطاقم المخوّل وحده
Broadcast::channel('exec.{execId}.staff', function (User $user, int $execId) {
    $exec = Execution::find($execId);

    return $exec ? ChannelAccess::staffCanSee($user, $exec) : false;
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

// غرفة الجلسة (`RoomStateChanged` · `RoomDetails::channel`) — حالُ الغرفة الحيّة: العميل المالك
// ومن يدخل الغرفة من الطاقم. وقناة `.staff` للطاقم وحده: تحمل التسجيل الذي لا يُخبَر به العميل.
Broadcast::channel('room.consult.{consultId}', function (User $user, int $consultId) {
    $consult = Consult::find($consultId);

    return $consult ? ChannelAccess::roomMember($user, $consult) : false;
});

Broadcast::channel('room.consult.{consultId}.staff', function (User $user, int $consultId) {
    $consult = Consult::find($consultId);

    return $consult ? ChannelAccess::roomStaff($user, $consult) : false;
});

Broadcast::channel('room.meeting.{meetingId}', function (User $user, int $meetingId) {
    $meeting = Meeting::find($meetingId);

    return $meeting ? ChannelAccess::roomMember($user, $meeting) : false;
});

Broadcast::channel('room.meeting.{meetingId}.staff', function (User $user, int $meetingId) {
    $meeting = Meeting::find($meetingId);

    return $meeting ? ChannelAccess::roomStaff($user, $meeting) : false;
});
