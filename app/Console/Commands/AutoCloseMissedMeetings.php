<?php

namespace App\Console\Commands;

use App\Domain\Journey\TransitionDenied;
use App\Domain\Journey\Transitions\Meeting\MarkMeetingMissed;
use App\Domain\Journey\Workflow;
use App\Models\Meeting;
use App\Models\MeetRequest;
use App\Support\MeetingTime;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use Illuminate\Console\Command;

/**
 * معالجة وحسم المواعيد والدعوات الفائتة آلياً (تثبيت للقاعدة — العرض الفوري تكفله liveState):
 * 1. الدعوات المعلقة التي مرّ موعدها بـ6 ساعات دون موافقة الإدارة → منتهية الصلاحية (STAGE_EXPIRED).
 * 2. قادم/مؤجل/بانتظار التأكيد **لم يبدأ قطّ** وتجاوز موعده مهلةَ الإعدادات (`meeting_autoclose_minutes`)
 *    ⇒ «لم ينعقد» عبر `MarkMeetingMissed`، ودعوته منتهية الصلاحية.
 *
 * **ولا يُنهي اجتماعاً بدأ** (قرار المالك 2026-09-26). كان هنا فرعان يُنهيان بالساعة: «قادمٌ»
 * دخله أحدٌ يُختم «منتهٍ» بعد ١٢ ساعة، و«جارٍ» يُختم بعد «المدة + ٣ ساعات». الاجتماع ينتهي حين
 * يُنهى (`EndMeeting`)؛ والمنسيّ منه يُنهى بعد مهلة النسيان مع تنبيه الطاقم من شبكةٍ واحدة
 * للاستشارات والاجتماعات معاً (`sessions:close-stale`) — لا بحسبة مدّة.
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
        $inviteExpiry = SettingsRegistry::int('meet_invite_expire_minutes');

        foreach ($pendingRequests as $req) {
            $dt = MeetingTime::parse($req->day, $req->time);
            if ($dt && $dt->copy()->addMinutes($inviteExpiry)->isPast()) {
                $req->update(['stage' => MeetRequest::STAGE_EXPIRED]);
                $expiredRequestsCount++;
            }
        }

        // 2. حسم القادمة الفائتة التي لم تبدأ — المهلة من الإعدادات (كانت ١٢ منقوشة)
        $minutes = SessionWindow::meetingAutocloseMinutes();
        $missedMeetingsCount = 0;
        // «بانتظار التأكيد» حالة تاريخية: لا يكتبها أي مسار حيّ منذ إلغاء تأكيد العميل — تبقى دفاعاً عن سجلّات قديمة
        $overdueMeetings = Meeting::whereIn('status', (new MarkMeetingMissed)->from())
            ->whereNull('join_time')
            ->get();

        foreach ($overdueMeetings as $meeting) {
            // starts_at الحقيقي يحسم أولاً؛ تحليل when_label احتياط للسجلات القديمة بلا موعد
            $dt = $meeting->startsAtResolved();
            if (! $dt || ! $dt->copy()->addMinutes($minutes)->isPast()) {
                continue;
            }

            try {
                Workflow::run(new MarkMeetingMissed, $meeting);
                $missedMeetingsCount++;
            } catch (TransitionDenied) {
                continue; // بدأ بين القراءة والقفل — يُنهى بإنهائه لا بالمجدول
            }
        }

        $this->info("تم حسم {$expiredRequestsCount} دعوة منتهية الصلاحية، و{$missedMeetingsCount} اجتماع لم ينعقد.");

        return self::SUCCESS;
    }
}
