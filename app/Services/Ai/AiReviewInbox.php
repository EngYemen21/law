<?php

namespace App\Services\Ai;

use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Meeting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * صندوق المراجعة الموحَّد — مخرجات تنتظر قرار إنسان، في مكان واحد.
 *
 * اليوم المراجعة مبعثرة: ملخّص التذكرة يُعتمد من شاشة المحامي، وتحليل الاستشارة من
 * شاشة الموظف، وتحليل التنفيذ لا شاشة اعتماد له أصلاً. فلا يعرف أحد **كم مخرجاً
 * ينتظر**، ولا أيّها عالي الخطورة، ولا كم بقي معلَّقاً. هذا الصنف يجمعها من `ai_runs`
 * بمقياس واحد: كل قيد حالته `needs_review` ولم يُبتّ فيه بعد.
 *
 * **العزل بالدور محفوظ:** المحامي يرى ما أُسند إليه، والموظف ما يخصّ التذاكر
 * والاستشارات، والإدارة كل شيء — نظير `ScopedToLawyer` في بقيّة المشروع.
 */
class AiReviewInbox
{
    /**
     * المهام التي يراها الموظّف — التشغيليّة لا القانونيّة.
     *
     * `document.analyze` منها: حكمٌ بأن مستنداً «مرتبط» أو لا، والمستندات في يد
     * الموظّف أصلاً. أُضيف حين صار الفحص يُسجَّل في `ai_runs` — وقبلها لم يكن يُسجَّل
     * فلا يظهر لأحد، فبقيت القائمة صحيحةً بالمصادفة لا بالقصد.
     *
     * وما يبقى خارجها: المسودّات والملخّصات والاستشهاد — رأيٌ قانونيّ لا يراجعه غير محامٍ.
     */
    private const EMPLOYEE_TASKS = ['triage', 'consult', 'document.analyze'];

    /**
     * المخرجات المنتظِرة لقرار هذا المستخدم.
     *
     * @return Collection<int, AiRun>
     */
    public static function forUser(User $user, int $limit = 50): Collection
    {
        return self::query($user)
            // `entity` تُحمَّل مسبقاً: `AiReviewPreview` تقرؤها لكل صفّ لتعرض نصّ
            // المخرج، فبلا تحميلٍ مسبق خمسون صفّاً = خمسون استعلاماً إضافياً
            ->with(['reviewer', 'entity'])
            ->orderByRaw('confidence IS NULL DESC') // ما لا يُقاس أولاً: لا يُعرف خطره
            ->orderBy('confidence')                  // ثم الأدنى ثقة
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /** عدّاد الصندوق — للشارة في القائمة. */
    public static function countFor(User $user): int
    {
        return self::query($user)->count();
    }

    /**
     * إحصاء أسباب الرفض — يحوّل قرارات المراجعين إلى بيانات تقييم.
     *
     * @return array<string,int> رمز السبب => العدد
     */
    public static function rejectionReasons(?int $days = 30): array
    {
        $query = AiRun::query()
            ->where('review_action', AiReviewAction::Reject->value)
            ->whereNotNull('review_reason');

        if ($days !== null) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        return $query->selectRaw('review_reason, count(*) as total')
            ->groupBy('review_reason')
            ->pluck('total', 'review_reason')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * نسبة ما احتاج يد إنسان — «تعديل» مقابل «قبول كما هو».
     * هذه هي جودة النموذج الحقيقيّة، لا نسبة ما اعتُمد.
     */
    public static function humanEditRate(?int $days = 30): ?float
    {
        $query = AiRun::query()->whereIn('review_action', [
            AiReviewAction::Accept->value,
            AiReviewAction::Edit->value,
        ]);

        if ($days !== null) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            return null; // لا قياس — لا صفر مضلِّل
        }

        return round((clone $query)->where('review_action', AiReviewAction::Edit->value)->count() / $total, 3);
    }

    /**
     * **هل يحقّ لهذا المستخدم أن يبتّ في هذا القيد؟**
     *
     * كان الصندوق يعزل **العرض** وحده: `query()` تُستعمل في `forUser`/`countFor` فقط،
     * بينما `AiReviewController::decide` يستقبل `AiRun` بربط النموذج ويُحدّثه مباشرةً.
     * فمحامٍ يحمل «اعتماد الملخصات» يعتمد بمعرّفٍ رقميّ ملخّصَ استشارةٍ **ليست مسندة
     * إليه** فيُطلقه لعميلها ويُشعره — وهو رأيٌ قانونيّ عن ملفٍّ لم يره.
     *
     * والسؤال هنا سؤال **سلطة** لا **حالة**: قيدٌ بُتّ فيه أو خرج من الصندوق يبقى
     * البتّ فيه حقّاً لصاحبه — فلا تُقحَم مرشّحات `query()` الزمنيّة في الحكم.
     */
    public static function mayDecide(User $user, AiRun $run): bool
    {
        return self::scopeToAuthority(AiRun::query()->whereKey($run->getKey()), $user)->exists();
    }

    /** الاستعلام الأساس: منتظِر للبتّ، منظوراً بعين الدور. */
    private static function query(User $user): Builder
    {
        return self::scopeToAuthority(
            AiRun::query()
                ->where('status', AiRun::STATUS_NEEDS_REVIEW)
                ->whereNull('review_action'), // لم يُبتّ فيه بعد
            $user
        );
    }

    /**
     * حدّ سلطة الدور — **الموضع الوحيد** الذي يقرّر «قيدُ مَن هذا».
     *
     * يقرؤه العرض (`query`) والبتّ (`mayDecide`) معاً، فلا يفترق الرأيان: صندوقٌ
     * يُخفي قيداً ومتحكّمٌ يقبل البتّ فيه هو بالضبط الثغرة التي وقعت.
     */
    private static function scopeToAuthority(Builder $query, User $user): Builder
    {
        return match ($user->role) {
            Role::Admin => $query,
            Role::Employee => $query->whereIn('task_type', self::EMPLOYEE_TASKS),
            // المحامي: **مخرجات ملفّاته** أوّلاً، ثم ما صُعِّد إليه.
            //
            // كان الشرط `escalated_to = me OR reviewed_by = me` وحده، والاستعلام الأساس
            // فيه `whereNull('review_action')` — فشقّه الثاني ميّتٌ عملياً (ما راجعه
            // فُصل بالفعل). أي أن المحامي لا يرى إلا ما صُعِّد إليه صراحةً.
            //
            // وأثره قيس حيّاً: مسودّة لائحةٍ على قضيّةٍ **مُسنَدة إليه هو**، حالتها
            // `needs_review`، لم تظهر في صندوقه (صفوف = 0)؛ والموظّف لا يراها لأنها
            // خارج `EMPLOYEE_TASKS`. فأخطر مخرجٍ قانونيّ في المنظومة لا يصل مراجعاً
            // مختصّاً أبداً — تراه الإدارة وحدها. وهذا نقضٌ لصندوق P4 من أصله:
            // التعليق أعلاه يقول «المحامي يرى ما أُسند إليه» والشيفرة تُنفّذ غيره.
            Role::Lawyer => $query->where(fn (Builder $q) => $q
                ->where('escalated_to', $user->id)
                ->orWhere(fn (Builder $inner) => self::ownedByLawyer($inner, $user->id))),
            default => $query->whereRaw('1 = 0'), // العميل لا يرى صندوق المراجعة إطلاقاً
        };
    }

    /**
     * القيود المرتبطة بكيانٍ مُسنَدٍ إلى هذا المحامي.
     *
     * الكيانات الأربعة تحمل `assigned_lawyer_id`، و`entity_type` يخزّن اسم الصنف
     * كاملاً (`$entity::class` في `AiRun::record`) فالمطابقة به مباشرة. وقيدٌ بلا
     * كيان (`entity_type = null`) لا يُنسب لأحد — يبقى للإدارة، ولا يُفترض أنه له.
     */
    private static function ownedByLawyer(Builder $query, int $lawyerId): Builder
    {
        // `Meeting` كان غائباً: قيود `meeting.summary` و`meeting.decisions` تحمل
        // `entity_type = Meeting` (`GenerateMeetingSummaryJob`)، فلا يطابقها شرطٌ
        // واحد — تبقى للإدارة وحدها ولا تبلغ محامي الاجتماع. وهو عين العطل
        // الموصوف أعلاه لمسودّات اللوائح. و`meeting.decisions` تُنشئ مهامّ على بشر.
        $entities = [Ticket::class, LegalCase::class, Execution::class, Consult::class, Meeting::class];

        foreach ($entities as $i => $class) {
            $clause = fn (Builder $q) => $q
                ->where('entity_type', $class)
                ->whereIn('entity_id', $class::query()
                    ->where('assigned_lawyer_id', $lawyerId)
                    ->select('id'));

            $i === 0 ? $query->where($clause) : $query->orWhere($clause);
        }

        return $query;
    }
}
