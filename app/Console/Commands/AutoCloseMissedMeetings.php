<?php

namespace App\Console\Commands;

use App\Events\MeetingStatusBroadcast;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Support\Live;
use App\Support\MeetingTime;
use Illuminate\Console\Command;

/**
 * معالجة وحسم المواعيد والدعوات الفائتة آلياً (تثبيت للقاعدة — العرض الفوري تكفله liveState):
 * 1. الدعوات المعلقة التي مرّ موعدها بـ6 ساعات دون تأكيد → منتهية الصلاحية (STAGE_EXPIRED).
 * 2. قادم/مؤجل/بانتظار التأكيد المتجاوز موعده بـ12 ساعة: دخل أحدٌ فعلاً ⇒ «منتهٍ» (لا استثناء
 *    يُبقيه قادماً للأبد كما كان قيد join_time===null)، ولم يدخل أحد ⇒ «لم ينعقد».
 * 3. «جارٍ» المتجاوز (المدة + 3 ساعات) ⇒ «منتهٍ» — كانت الجارية أبديةً لا يحسمها أحد.
 * وترتفع مراحل الدعوات المرتبطة تبعاً (منعقد ⇒ تنفيذ الجلسة، لم ينعقد ⇒ منتهية الصلاحية).
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

        // 2. حسم القادمة الفائتة (12 ساعة): منعقدة فعلاً ⇒ منتهٍ، وإلا ⇒ لم ينعقد
        $missedMeetingsCount = 0;
        $endedMeetingsCount = 0;
        $overdueMeetings = Meeting::whereIn('status', ['قادم', 'مؤجل', 'بانتظار التأكيد'])->get();

        foreach ($overdueMeetings as $meeting) {
            // starts_at الحقيقي يحسم أولاً؛ تحليل when_label احتياط للسجلات القديمة بلا موعد
            $dt = $meeting->startsAtResolved();
            if (! $dt || ! $dt->copy()->addHours(12)->isPast()) {
                continue;
            }

            if ($meeting->join_time !== null) {
                // دخل أحد الأطراف فعلاً — الاجتماع انعقد وإن لم يُنهه أحد يدوياً
                $this->closeAsEnded($meeting);
                $endedMeetingsCount++;
            } else {
                $meeting->update(['status' => 'لم ينعقد']);
                // دعوة اجتماع لم ينعقد لم تعد قابلة للدخول — تُعلَّم منتهية الصلاحية (يُتاح إعادة إرسالها)
                MeetRequest::where('meeting_id', $meeting->id)
                    ->where('stage', '<', MeetRequest::STAGE_EXECUTED)
                    ->update(['stage' => MeetRequest::STAGE_EXPIRED]);
                Live::push(new MeetingStatusBroadcast($meeting));
                $missedMeetingsCount++;
            }
        }

        // 3. حسم «جارٍ» الأبدية: تجاوزت (المدة + 3 ساعات) ⇒ منتهٍ
        foreach (Meeting::where('status', 'جارٍ')->get() as $meeting) {
            $dt = $meeting->startsAtResolved();
            if ($dt && $dt->copy()->addMinutes($meeting->durationMinutes())->addHours(3)->isPast()) {
                $this->closeAsEnded($meeting);
                $endedMeetingsCount++;
            }
        }

        $this->info("تم حسم {$expiredRequestsCount} دعوة منتهية الصلاحية، و{$missedMeetingsCount} اجتماع لم ينعقد، و{$endedMeetingsCount} اجتماع منتهٍ.");

        return self::SUCCESS;
    }

    /** إنهاء الاجتماع بنفس دلالة الإنهاء اليدوي/الويبهوك: حضور افتراضي + رفع مرحلة الدعوة + بثّ. */
    private function closeAsEnded(Meeting $meeting): void
    {
        $meeting->update([
            'status' => 'منتهٍ',
            'attend' => $meeting->attend ?: 90,
        ]);
        MeetRequest::where('meeting_id', $meeting->id)
            ->where('stage', '<', MeetRequest::STAGE_EXECUTED)
            ->update(['stage' => MeetRequest::STAGE_EXECUTED]);
        Live::push(new MeetingStatusBroadcast($meeting));
    }
}
