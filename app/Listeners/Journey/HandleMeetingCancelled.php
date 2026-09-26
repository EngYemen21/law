<?php

namespace App\Listeners\Journey;

use App\Events\Journey\MeetingCancelled;
use App\Jobs\DropZoomMeetingJob;
use App\Support\Audit;

/**
 * آثار إلغاء الاجتماع بعد الالتزام — **مهما كان الباب** (زرّ الاجتماع أو إلغاء دعوته):
 *
 * - **Zoom:** تُحذف الغرفة في الطابور بإعادة محاولة (`DropZoomMeetingJob`) — كان زرّ الاجتماع يحذفها
 *   حذفاً متزامناً يقف عليه، وإلغاء الدعوة لا يحذفها إطلاقاً فتبقى غرفةٌ حيّة لاجتماعٍ «قادم» لا
 *   صاحب له. ولا «إنهاء» قبل الحذف: الجاري لا يُلغى أصلاً (`CancelMeeting::guard`).
 * - **التدقيق:** سطرٌ واحد بالمصدر. والإشعار والبريد عند كلّ باب بنصّه (دعوةٌ أم اجتماع).
 */
final class HandleMeetingCancelled
{
    public function handle(MeetingCancelled $event): void
    {
        $meeting = $event->meeting;

        if ($event->oldMeetId !== null) {
            DropZoomMeetingJob::dispatch($event->oldMeetId, (string) $meeting->ref);
        }

        $actorName = $event->actor->name ?? 'النظام';
        Audit::log(
            action: 'إلغاء اجتماع',
            description: $event->via === MeetingCancelled::VIA_INVITATION
                ? "ألغى {$actorName} دعوة الاجتماع «{$meeting->title}» ({$meeting->ref}) — فأُلغي الاجتماع وحُذفت غرفته."
                : "ألغى {$actorName} الاجتماع «{$meeting->title}» ({$meeting->ref}).",
            category: 'اجتماعات',
            severity: 'warning',
            auditable: $meeting,
        );
    }
}
