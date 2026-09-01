<?php

namespace App\Services\Ai;

use App\Events\ConsultStatusBroadcast;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\User;
use App\Support\Live;
use App\Support\Notify;

/**
 * أثر قرار المراجعة في الملفّ — لا في `ai_runs` وحده.
 *
 * `AiReviewController::decide` كان يسجّل القرار ويقف: يقبل المراجعُ مخرجاً فلا يتغيّر
 * شيء في الملفّ. وهذا مقبولٌ ما دام المخرج معروضاً أصلاً، لكنه يصير عطلاً حين
 * يُحجب المخرج عن العميل بانتظار اعتماد — فيبقى محجوباً وإن اعتُمد.
 *
 * ولذلك تُفصل الآثار هنا لا تُحشر في المتحكّم: كل مهمّة يُراد لقبولها أثرٌ في ملفّها
 * تُضاف بفرعٍ واحد، ويبقى المتحكّم رقيقاً.
 */
class AiReviewOutcome
{
    /** يُطبّق أثر القرار إن كان له أثر. يُنادى **بعد** تسجيل القرار. */
    public static function apply(AiRun $run, AiReviewAction $action, User $reviewer): void
    {
        // القبول والتعديل كلاهما اعتمادٌ بشريّ: الأوّل «صحيح كما هو» والثاني
        // «صحيح بعد تحريري». والرفض وإعادة التشغيل والتصعيد لا تُطلق مخرجاً.
        if ($action !== AiReviewAction::Accept && $action !== AiReviewAction::Edit) {
            return;
        }

        match ($run->task_type) {
            'consult.summary' => self::releaseConsultSummary($run, $reviewer),
            default => null,
        };
    }

    /**
     * إطلاق ملخّص الاستشارة إلى العميل بعد اعتماد محامٍ.
     *
     * الإشعار **هنا** لا عند التوليد: كان يُرسل فور كتابة النموذج للملخّص («ملخص
     * الاستشارة متاح الآن») بينما لم يمرّ به إنسان. فصار الإشعار يتبع الاعتماد لا
     * الإنتاج، ويصل العميل مرّةً واحدة — `summary_approved_at` يحرس التكرار.
     */
    private static function releaseConsultSummary(AiRun $run, User $reviewer): void
    {
        $consult = $run->entity instanceof Consult
            ? $run->entity
            : Consult::where('ref', $run->entity_ref)->first();

        if ($consult === null || $consult->summaryApproved() || blank($consult->summary)) {
            return;
        }

        $consult->update([
            'summary_approved_at' => now(),
            'summary_approved_by' => $reviewer->id,
        ]);

        $consult->logAudit($reviewer->name, 'اعتماد ملخّص الاستشارة', 'مبدئيّ', 'معتمد');

        Notify::send(
            $consult->user_id,
            'doc',
            't-green',
            "اعتُمد ملخّص استشارتك ({$consult->ref}) وهو متاح الآن في «استشاراتي»."
        );

        Live::push(new ConsultStatusBroadcast($consult->fresh()));
    }
}
