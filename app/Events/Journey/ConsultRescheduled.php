<?php

namespace App\Events\Journey;

use App\Models\Consult;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final class ConsultRescheduled
{
    use Dispatchable;

    public function __construct(
        public readonly Consult $consult,
        public readonly string $oldWhen,
        public readonly ?string $oldMeetId,
        // الفاعل نفسه لا اسمه: المستمع يستثنيه من التنبيه، ولا يُعرف «من فعل» من اسمٍ نصّيّ
        public readonly ?User $actor,
        public readonly bool $ticketReverted,
        public readonly string $reason,
    ) {}
}
