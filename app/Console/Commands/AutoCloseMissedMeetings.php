<?php

namespace App\Console\Commands;

use App\Events\MeetingStatusBroadcast;
use App\Models\MeetRequest;
use App\Models\Meeting;
use App\Support\Live;
use App\Support\MeetingTime;
use Illuminate\Console\Command;

/**
 * معالجة وحسم المواعيد والدعوات الفائتة آلياً:
 * 1. الدعوات المعلقة التي مرّ موعدها بـ 6 ساعات دون تأكيد → تحديث حالتها إلى منتهية الصلاحية (STAGE_EXPIRED).
 * 2. الاجتماعات القادمة التي تجاوزت موعدها بـ 12 ساعة ولم يدخل أي طرف ولم تُنهَ → تحديث حالتها إلى «لم ينعقد».
 */
class AutoCloseMissedMeetings extends Command
{
    protected $signature = 'zoom:auto-close-missed';

    protected $description = 'حسم ومعالجة الاجتماعات والدعوات القديمة غير المنعقدة تلقائياً';

    public function handle(): int
    {
        // 1. حسم دعوات الاجتماعات المعلقة القديمة
        $expiredRequestsCount = 0;
        $pendingRequests = MeetRequest::where('stage', MeetRequest::STAGE_SENT)->get();

        foreach ($pendingRequests as $req) {
            $dt = MeetingTime::parse($req->day, $req->time);
            if ($dt && $dt->addHours(6)->isPast()) {
                $req->update(['stage' => MeetRequest::STAGE_EXPIRED]);
                $expiredRequestsCount++;
            }
        }

        // 2. حسم الاجتماعات القديمة التي لم تنعقد (مضى على موعدها أكثر من 12 ساعة)
        $missedMeetingsCount = 0;
        $overdueMeetings = Meeting::whereIn('status', ['قادم', 'مؤجل'])
            ->get();

        foreach ($overdueMeetings as $meeting) {
            $dt = $meeting->starts_at ?: MeetingTime::parse(explode('·', $meeting->when_label)[0] ?? '', explode('·', $meeting->when_label)[1] ?? '');
            if ($dt && $dt->addHours(12)->isPast() && $meeting->join_time === null) {
                $meeting->update([
                    'status' => 'لم ينعقد',
                    'is_up' => false,
                ]);
                Live::push(new MeetingStatusBroadcast($meeting));
                $missedMeetingsCount++;
            }
        }

        $this->info("تم حسم {$expiredRequestsCount} دعوة منتهية الصلاحية، و{$missedMeetingsCount} اجتماع لم ينعقد.");

        return self::SUCCESS;
    }
}
