<?php

namespace App\Support;

use App\Enums\AiSource;
use App\Events\CaseMessageBroadcast;
use App\Events\CaseStatusBroadcast;
use App\Models\AiRun;
use App\Models\CaseMessage;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\Ai\AiReviewAction;

/**
 * **اعتمادُ لائحة الدعوى — مسارٌ واحد لا اعتمادان.** (قرار المالك 2026-09-11)
 *
 * كان للّائحة اعتمادان منفصلان لا يعرف أحدُهما الآخر:
 * - زرُّ المحامي (`Lawyer\CaseController::approvePleading`) ينقل القضيّة إلى «منظورة»
 *   ويُبلغ العميل «تم اعتماد لائحة قضيتك» — والمسودّةُ محجوبةٌ عنه لم يرَ نصَّها.
 * - وقبولُ صندوق المراجعة (`AiReviewOutcome::releaseCasePleading`) يُطلق النصّ ويترك
 *   القضيّة في «قيد التحضير».
 *
 * فصار الاثنان يناديان `approve()` هنا: تُطلَق أحدث مسودّة، وتُرفع الدعوى إن كانت
 * بانتظار الاعتماد — ويصل العميلَ إشعارٌ واحد يَصدُق.
 */
final class CasePleading
{
    public const DRAFT_ROLE = 'مسودة اللائحة';

    /** علامة التنبيه الداخليّ في نصّ المسودّة (أسانيد لم تُطابَق، مخرجٌ مبتور، لم تُنتَج). */
    public const WARNING_MARK = '⚠️';

    /**
     * هل تحمل أحدث مسودّة تنبيهاً داخلياً؟ التنبيه موجَّهٌ للمحامي — واعتمادُه يُرسله للعميل
     * مع اللائحة ويُقدَّم للمحكمة. يُعالَج ما يشير إليه ثم يُحذف ويُحفظ.
     */
    public static function hasWarnings(LegalCase $case): bool
    {
        return str_contains((string) self::latestDraft($case)?->body, self::WARNING_MARK);
    }

    /** أحدث مسودّة — `reorder` لأنّ العلاقة مرتّبةٌ تصاعدياً في تعريفها (`latest` تُلحق لا تستبدل). */
    public static function latestDraft(LegalCase $case): ?CaseMessage
    {
        return $case->messages()->where('role', self::DRAFT_ROLE)->reorder('id', 'desc')->first();
    }

    /** آخر قيد ذكاءٍ للّائحة — يحمل مصدرها (احتياطيّ؟) وقرار المراجع (رفض؟). */
    public static function latestRun(LegalCase $case): ?AiRun
    {
        return AiRun::where('task_type', 'case.pleading')
            ->where('entity_ref', $case->number)
            ->reorder('id', 'desc')
            ->first();
    }

    /**
     * سببُ تعذّر الاعتماد الآن، أو `null` إن جاز.
     *
     * - **لا مسودّة:** لا يُبلَّغ العميل باعتماد لائحةٍ لا نصَّ لها.
     * - **المسودّة نصٌّ احتياطيّ:** حين يتعذّر المزوّد يُكتب «تعذّر توليد المسودّة…» **بالدور
     *   نفسه**، فكان اعتمادُه يُطلقه للعميل بوصفه لائحة الدعوى ويرفع الدعوى به (كشفه تتبّعٌ
     *   لمسار المسودّة في 2026-09-11).
     * - **رُفضت في صندوق المراجعة:** قرارُ الرفض لا يُتجاوَز بزرٍّ في شاشة الملفّ.
     */
    public static function blockReason(LegalCase $case): ?string
    {
        $draft = self::latestDraft($case);

        if ($draft === null) {
            return 'لم تُعدّ مسودّة لائحة الدعوى بعد — اكتبها في المحرّر أو انتظر توليدها.';
        }

        // أيّاً كان كاتبُ النصّ: التنبيه الباقي فيه يصل العميل والمحكمة إن اعتُمد
        if (self::hasWarnings($case)) {
            return 'المسودّة تحمل تنبيهاً (⚠️) — عالِج ما يشير إليه في المحرّر، واحذف التنبيه ثم احفظ قبل الاعتماد.';
        }

        // **نصٌّ حرّره المحامي** لا يحجبه حكمٌ على مخرج الآلة: الاحتياطيّ والرفض يخصّان
        // ما كتبه النموذج، ومن أعاد الكتابة بيده فقد تجاوز المخرجَ الذي حُكم عليه.
        if (self::isHumanAuthored($draft)) {
            return null;
        }

        $run = self::latestRun($case);

        if ($run?->source === AiSource::Fallback) {
            return 'تعذّر توليد المسودّة آلياً — النصّ الحاليّ احتياطيّ ولا يُعتمد لائحةً للدعوى.';
        }

        if ($run?->review_action === AiReviewAction::Reject) {
            return 'رُفضت المسودّة في صندوق مراجعة مخرجات الذكاء — لا تُعتمد بعد الرفض.';
        }

        return null;
    }

    /** مسودّةٌ كتبها أو حرّرها إنسان — تُحفظ بـ`who` صاحبها لا بـ«ai». */
    public static function isHumanAuthored(CaseMessage $draft): bool
    {
        return $draft->who !== 'ai';
    }

    /** نصّ المسودّة للمحرّر — بلا وسومٍ ولا كياناتٍ مرمَّزة (نظير `AiReviewPreview::pleading`). */
    public static function draftText(?CaseMessage $draft): ?string
    {
        if ($draft === null) {
            return null;
        }

        return trim(html_entity_decode(strip_tags((string) $draft->body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * **حفظ المسودّة — تبقى محجوبةً وقابلةً للتعديل.** (قاعدة المنظومة: الحفظ مسودّة، والاعتماد
     * النهائيّ يقفل.) تُحرَّر أحدثُ مسودّةٍ محجوبة في مكانها — فتصير نصّاً بشرياً — أو تُنشأ
     * واحدةٌ إن لم تكن. ولا تحرير بعد الاعتماد: الخادم يرفض قبل أن يصل هنا.
     */
    public static function save(LegalCase $case, User $author, string $text): CaseMessage
    {
        $body = '<div class="draft" style="white-space:pre-line">'.e($text).'</div>';
        $draft = self::latestDraft($case);

        if ($draft !== null && $draft->withheld_at !== null) {
            $draft->update(['who' => 'lawyer', 'name' => $author->name, 'body' => $body]);

            return $draft->fresh();
        }

        return $case->messages()->create([
            'who' => 'lawyer',
            'name' => $author->name,
            'role' => self::DRAFT_ROLE,
            'body' => $body,
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
            // محجوبة كمسودّات الآلة — لا يراها العميل قبل الاعتماد النهائيّ
            'withheld_at' => now(),
        ]);
    }

    public static function approve(LegalCase $case, User $actor): void
    {
        if ($case->pleading_status === 'pending_lawyer') {
            self::release($case);
            self::approveText($case, $actor);

            return;
        }

        // لائحةٌ اعتُمدت سابقاً ومسودّتُها بقيت محجوبة (حجبتها هجرة `withheld_at` بأثرٍ رجعيّ)
        self::releaseForApprovedCase($case);
    }

    /**
     * يُطلق مسودّة قضيّةٍ اعتُمدت لائحتُها ويُشعر العميل — يناديه `approve` وأمرُ
     * `cases:release-approved-pleadings`. يُعيد `false` إن لم يكن ثمّة محجوبٌ يُطلَق.
     */
    public static function releaseForApprovedCase(LegalCase $case): bool
    {
        if (! self::release($case)) {
            return false;
        }

        Notify::send(
            $case->user_id,
            'doc',
            't-green',
            "اعتمد المستشار مسودّة لائحة الدعوى في قضيتك ({$case->number}) وهي متاحة الآن في ملفّ القضية."
        );
        Live::push(new CaseStatusBroadcast($case->fresh()));

        return true;
    }

    /** يُطلق أحدث مسودّة محجوبة ويبثّها — البثّ هنا لأنّ `CaseMessage::booted` يتخطّى المحجوبة. */
    private static function release(LegalCase $case): bool
    {
        $draft = self::latestDraft($case);

        if ($draft === null || $draft->withheld_at === null) {
            return false;
        }

        $draft->update(['withheld_at' => null]);
        Live::push(new CaseMessageBroadcast($draft->fresh()));

        return true;
    }

    /**
     * **اعتماد النصّ لا رفع الدعوى.** (قرار المالك 2026-09-11 — الخطّة ب)
     *
     * كان الاعتماد يكتب «منظورة» ويبلّغ العميل «رُفعت الدعوى» قبل أن ترفعها أيّ يدٍ في ناجز.
     * الاعتمادُ يقفل النصّ ويُتيحه للعميل، والقضيّة تبقى «قيد التحضير» حتى يُسجَّل الرفع ثمّ القيد
     * (`CaseFiling::file` ثمّ `CaseFiling::register`).
     */
    private static function approveText(LegalCase $case, User $actor): void
    {
        $case->update([
            'pleading_status' => 'approved',
            'update_text' => 'اعتُمدت لائحة الدعوى — بانتظار رفعها في ناجز',
        ]);

        $case->messages()->create([
            'who' => $actor->isLawyer() ? 'lawyer' : 'admin',
            'name' => $actor->name,
            'role' => 'اعتماد اللائحة',
            'body' => '<p>اعتُمدت لائحة الدعوى نهائياً، وتُرفع صحيفتُها عبر منصّة ناجز.</p>',
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);

        Notify::send($case->user_id, 'scale', 't-blue', "اعتمد مستشارك لائحة دعوى قضيتك {$case->number}، وتُرفع عبر منصّة ناجز قريباً.");

        Audit::log(
            action: 'اعتماد لائحة دعوى',
            description: "اعتمد {$actor->name} لائحة الدعوى للقضية {$case->number} — بانتظار رفعها في ناجز.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['اللائحة' => 'معتمدة'],
        );

        Live::push(new CaseStatusBroadcast($case));
    }
}
