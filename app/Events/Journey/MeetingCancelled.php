<?php

namespace App\Events\Journey;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * **أُلغي اجتماعٌ** — حدثٌ بعد التزام `Meeting\CancelMeeting`، يحمل معرّف غرفة Zoom التي صُفّر عمودها
 * في الانتقال نفسه (فلا يُقرأ من الكيان بعده). مستمعه يحذف الغرفة في الطابور ويدوّن التدقيق.
 *
 * `$via`: من أين جاء الإلغاء — `meeting` (زرّ الاجتماع) أو `invitation` (إلغاء دعوته).
 */
final class MeetingCancelled
{
    use Dispatchable;

    public const VIA_MEETING = 'meeting';

    public const VIA_INVITATION = 'invitation';

    public function __construct(
        public readonly Meeting $meeting,
        public readonly ?string $oldMeetId,
        public readonly ?User $actor,
        public readonly string $via,
    ) {}
}
