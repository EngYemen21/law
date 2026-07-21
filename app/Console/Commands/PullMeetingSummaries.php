<?php

namespace App\Console\Commands;

use App\Models\Consult;
use App\Models\Meeting;
use App\Services\ZoomService;
use App\Support\ConsultSummary;
use App\Support\MeetingSummary;
use Illuminate\Console\Command;

/**
 * يجلب ملخّص AI Companion من Zoom للاستشارات المرئية المنتهية ويحلّه محلّ الملخّص المؤقّت،
 * ثم يبثّه لحظياً للعميل. غير متزامن (يجهز بعد دقائق) لذا يُشغَّل دورياً؛ idempotent عبر zoom_summary_at.
 */
class PullMeetingSummaries extends Command
{
    protected $signature = 'zoom:pull-summaries';

    protected $description = 'جلب ملخّص AI Companion من Zoom للجلسات المرئية المنتهية وعرضه للعميل';

    public function handle(ZoomService $zoom): int
    {
        // الاستشارات المرئية المنتهية
        $consults = Consult::where('channel', 'مرئية')
            ->where('session', 'منتهية')
            ->whereNotNull('meet_id')->where('meet_id', '!=', '')
            ->whereNull('zoom_summary_at')
            ->where('updated_at', '>=', now()->subDay()) // نافذة معقولة — لا نلاحق القديم للأبد
            ->get();

        // اجتماعات المكتب المنتهية
        $meetings = Meeting::where('status', 'منتهٍ')
            ->whereNotNull('meet_id')->where('meet_id', '!=', '')
            ->whereNull('zoom_summary_at')
            ->where('updated_at', '>=', now()->subDay())
            ->get();

        $pulled = 0;
        foreach ($consults as $consult) {
            $pulled += ConsultSummary::pull($consult, $zoom) ? 1 : 0;
        }
        foreach ($meetings as $meeting) {
            $pulled += MeetingSummary::pull($meeting, $zoom) ? 1 : 0;
        }

        $pending = $consults->count() + $meetings->count();
        $this->info('جُلب '.$pulled.' ملخّص Zoom من '.$pending.' جلسة معلّقة (استشارات + اجتماعات).');

        return self::SUCCESS;
    }
}
