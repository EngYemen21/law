<?php

namespace App\Services\Ai;

use App\Enums\Role;
use App\Models\AiRun;
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
            ->with('reviewer')
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

    /** الاستعلام الأساس: منتظِر للبتّ، منظوراً بعين الدور. */
    private static function query(User $user): Builder
    {
        $query = AiRun::query()
            ->where('status', AiRun::STATUS_NEEDS_REVIEW)
            ->whereNull('review_action'); // لم يُبتّ فيه بعد

        return match ($user->role) {
            Role::Admin => $query,
            Role::Employee => $query->whereIn('task_type', self::EMPLOYEE_TASKS),
            // المحامي: ما صُعِّد إليه، وما راجعه سابقاً — لا مخرجات ملفّات زملائه
            Role::Lawyer => $query->where(fn (Builder $q) => $q
                ->where('escalated_to', $user->id)
                ->orWhere('reviewed_by', $user->id)),
            default => $query->whereRaw('1 = 0'), // العميل لا يرى صندوق المراجعة إطلاقاً
        };
    }
}
