<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiBlindReview;
use App\Services\Ai\AiBlindSample;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewReason;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الطبقة الثالثة — مراجعة عيّنة **عمياء**.
 *
 * الخطة تفرض «عيّنة دوريّة يراجعها محامٍ **لا يعرف** أنها من الذكاء»، ثم تُقارن
 * نتيجته بحكم الطبقة الثانية. ولم تكن ثمّة آليّة: شاشة المراجعة الوحيدة تعرض المصدر
 * والنموذج وإصدار التعليمة ودرجة الثقة — فالمحامي يعرف قبل أن يحكم، وحكمه بعدها ليس
 * شهادةً مستقلّة.
 *
 * **العمى هنا عقدٌ لا عرضٌ:** ما قبل الحكم لا يُرسَل أصلاً إلى المتصفّح — لا مصدر ولا
 * نموذج ولا ثقة. إخفاؤها بـCSS يُبقيها في الحمولة، ومن يفتح أدوات المطوّر يراها.
 */
class AiBlindReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $items = AiBlindReview::with('run')
            ->where('reviewer_id', $user->id)
            ->orderByRaw('judged_at IS NULL DESC')
            ->orderByDesc('id')
            ->get()
            ->map(function (AiBlindReview $review) {
                $judged = $review->judged();

                return [
                    'id' => $review->id,
                    'taskType' => $review->run?->task_type,
                    'entityRef' => $review->run?->entity_ref ?? '—',
                    'createdAt' => $review->run?->created_at?->locale('ar')->translatedFormat('d F Y'),
                    'judged' => $judged,
                    'verdict' => $review->verdict?->value,
                    'verdictLabel' => $review->verdict?->label(),
                    'reasonLabel' => $review->reason?->label(),

                    // ── ما لا يُكشف قبل الحكم ──
                    // يُحجب في الخادم لا في الواجهة: حجبٌ بـCSS يُبقيه في الحمولة
                    'machineStatus' => $judged ? $review->machine_status : null,
                    'machineConfidence' => $judged ? $review->machine_confidence : null,
                    'agrees' => $judged ? $review->agrees() : null,
                    'wasBlind' => $judged ? $review->wasBlind() : null,
                ];
            })
            ->values();

        return Inertia::render('admin/ai-blind-review', [
            'items' => $items,
            'summary' => AiBlindSample::summary($user),
            'actions' => AiReviewAction::options(),
            'reasons' => AiReviewReason::options(),
            'defaultSize' => AiBlindSample::DEFAULT_SIZE,
            'maxSize' => AiBlindSample::MAX_SIZE,
        ]);
    }

    /** سحب عيّنة جديدة — عشوائيّة، وبلا ما سبق أن راجعه هذا المراجع. */
    public function draw(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'size' => ['nullable', 'integer', 'min:1', 'max:'.AiBlindSample::MAX_SIZE],
        ]);

        $drawn = AiBlindSample::draw($request->user(), $data['size'] ?? AiBlindSample::DEFAULT_SIZE);

        return back()->with('flash', $drawn === 0
            ? 'لا مخرجات جديدة صالحة للسحب — كلّها مُراجَعة أو بلا ثقة مقيسة.'
            : "سُحبت {$drawn} حالة للمراجعة العمياء.");
    }

    /**
     * تسجيل حكم المحامي.
     *
     * الحكم يُكتب **قبل** الكشف، ويُختم بوقته. فصلُ الزمنين هو ما يجعل «الأعمى» واقعةً
     * مسجَّلة لا ادّعاءً.
     */
    public function judge(Request $request, AiBlindReview $review): RedirectResponse
    {
        abort_unless($review->reviewer_id === $request->user()->id, 403);

        $data = $request->validate([
            'verdict' => ['required', Rule::enum(AiReviewAction::class)],
            'reason' => ['nullable', Rule::enum(AiReviewReason::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $verdict = AiReviewAction::from($data['verdict']);

        if ($verdict->requiresReason() && empty($data['reason'])) {
            return back()->withErrors(['reason' => 'الرفض يلزمه سبب منظَّم.']);
        }

        if ($review->judged()) {
            return back()->withErrors(['verdict' => 'حُكم على هذه الحالة سابقاً — لا يُعاد الحكم بعد الكشف.']);
        }

        $review->update([
            'verdict' => $verdict->value,
            'reason' => $data['reason'] ?? null,
            'note' => $data['note'] ?? null,
            'judged_at' => now(),
            // الكشف يقع بعد الحكم مباشرةً: النتيجة تُعرض للمحامي ليتعلّم منها
            'revealed_at' => now(),
        ]);

        Audit::log(
            action: 'حكم في مراجعة عمياء',
            description: "حكم «{$verdict->label()}» على مخرج {$review->run?->task_type} — قبل كشف مصدره.",
            category: 'المساعد القانوني',
            auditable: $review,
        );

        return back()->with('flash', 'سُجِّل حكمك، وكُشف حكم النظام للمقارنة.');
    }
}
