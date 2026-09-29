<?php

namespace App\Console\Commands;

use App\Domain\Journey\Enums\HearingStatus;
use App\Models\CaseHearing;
use App\Support\EventStatus;
use App\Support\Notify;
use Illuminate\Console\Command;

/**
 * حسم جلسات القضايا الفائتة آلياً — «مجدولة» فات موعدها بيوم كامل دون تسجيل نتيجة
 * ⇒ «بانتظار تسجيل النتيجة» + تحديث «الجلسة القادمة» للقضية + تنبيه المحامي المسند.
 * (العرض الفوري تكفله isLapsed؛ هذا الأمر يثبّت القاعدة كي لا تبقى «مجدولة» كاذبة للأبد.)
 */
class AutoLapseHearings extends Command
{
    protected $signature = 'hearings:auto-lapse';

    protected $description = 'وسم جلسات القضايا الفائتة «بانتظار تسجيل النتيجة» وتنبيه محاميها';

    public function handle(): int
    {
        $count = 0;
        $lapsed = CaseHearing::with('legalCase')
            ->where('status', HearingStatus::Scheduled->value)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now()->subDay())
            ->get();

        foreach ($lapsed as $hearing) {
            // السلسلة الموحَّدة: كانت «بانتظار تسجيل النتيجة» سلسلة ثانية لا تعرفها شروط أزرار
            // تسجيل النتيجة (تشترط «مجدولة») ولا خريطة الألوان — فتختفي الأزرار وتزرقّ الشارة
            $hearing->update(['status' => EventStatus::HEARING_LAPSED]);

            if ($case = $hearing->legalCase) {
                // تثبيت «الجلسة القادمة» المخزّنة بعد خروج الفائتة من المجدولة
                $case->update([
                    'next_hearing' => $case->nextHearingLabel(),
                    'update_text' => 'جلسة فائتة بانتظار تسجيل نتيجتها: '.$hearing->title,
                ]);
                if ($case->assigned_lawyer_id) {
                    Notify::send($case->assigned_lawyer_id, 'cal', 't-amber', "جلسة «{$hearing->title}» على القضية {$case->number} فات موعدها دون نتيجة — سجّل نتيجتها من صفحة القضية.");
                }
            }
            $count++;
        }

        $this->info("تم وسم {$count} جلسة فائتة بانتظار تسجيل النتيجة.");

        return self::SUCCESS;
    }
}
