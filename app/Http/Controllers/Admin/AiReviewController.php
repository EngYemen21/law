<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Services\Ai\AiConfidence;
use App\Services\Ai\AiCost;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AiOpsMetrics;
use App\Services\Ai\AiPromptRegistry;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewEntityState;
use App\Services\Ai\AiReviewInbox;
use App\Services\Ai\AiReviewOutcome;
use App\Services\Ai\AiReviewPreview;
use App\Services\Ai\AiReviewReason;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * صندوق مراجعة مخرجات الذكاء — الشاشة الموحَّدة التي تفرضها المرحلة P3.
 *
 * قبلها كانت المراجعة مبعثرة: ملخّص التذكرة يُعتمد من شاشة المحامي، وتحليل
 * الاستشارة من شاشة الموظف، وتحليل التنفيذ بلا شاشة أصلاً — فلا يعرف أحد كم
 * مخرجاً ينتظر ولا أيّها عالي الخطورة ولا كم بقي معلَّقاً.
 */
class AiReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('admin/ai-review', [
            'items' => AiReviewInbox::forUser($user)->map(fn (AiRun $run) => [
                'id' => $run->id,
                'taskType' => $run->task_type,
                // الاسم من سجلّ التعليمات — الواجهة لا تحمل خريطة أسماءٍ تنقص مهمّةً فتظهر برمزها
                'taskLabel' => AiPromptRegistry::taskLabel($run->task_type),
                'entityRef' => $run->entity_ref ?? '—',
                'source' => $run->source?->value,
                'sourceLabel' => $run->source?->label(),
                // null يُعرض «غير مقيسة» لا صفراً — لا يُدّعى قياسٌ لم يقع
                'confidence' => $run->confidence,
                'confidenceSignals' => $run->confidence_signals,
                // الإشارات بأسمائها العربيّة من جوار تعريفها — لا خريطة مخمَّنة في المتصفّح
                'signalRows' => AiConfidence::describe($run->confidence_signals),
                // دليل تقليل البيانات أمام المراجع: كم معرّفاً مُوّه وكم غادر الخادم
                'outboundAudit' => $run->outbound_audit,
                'model' => $run->model ?? '—',
                'promptVersion' => $run->prompt_version ?? '—',
                'failureCode' => $run->failure_code,
                'failureLabel' => AiFailure::label($run->failure_code),
                'traceId' => $run->trace_id,
                // **النصّ نفسه** لا بياناته وحدها: كان الاعتماد يقع على المصدر
                // والثقة والنموذج بلا رؤية ما سيقرؤه الإنسان. `null` = لا مخرج محفوظ.
                'preview' => AiReviewPreview::for($run, $request->user()),
                // حالة الملفّ الآن وأثرُ القبول إن لم يصل العميل — فلا يُعتمد مخرجٌ لملفٍّ تجاوزه دون علم
                'entityState' => AiReviewEntityState::for($run),
                'createdAt' => $run->created_at?->locale('ar')->translatedFormat('d F Y · h:i A'),
            ])->values(),
            'actions' => AiReviewAction::options(),
            'reasons' => AiReviewReason::options(),
            // من يُصعَّد إليه — القائمة نفسها التي يتحقّق بها `decide`. كان الفعل يلزمه
            // `escalated_to` والشاشة لا تُرسله، فلم ينجح تصعيدٌ واحد قطّ.
            'assignees' => AiReviewInbox::escalationTargets($user),
            // عملة الكلفة من مصدرها — كانت هذه الشاشة تكتب «ر.س» ولوحة التشغيل «$» للرقم نفسه
            'currency' => AiCost::CURRENCY,
            'metrics' => [
                'pending' => AiReviewInbox::countFor($user),
                'editRate' => AiReviewInbox::humanEditRate(),
                'rejectionReasons' => AiReviewInbox::rejectionReasons(),
                'ops' => AiOpsMetrics::snapshot(),
                'alerts' => AiOpsMetrics::alerts(),
            ],
        ]);
    }

    /** تسجيل قرار المراجع — الرفض يلزمه سبب منظَّم، والتصعيد يلزمه مُصعَّدٌ إليه. */
    public function decide(Request $request, AiRun $run): RedirectResponse
    {
        // **العزل يسبق كلّ شيء.** كان الصندوق يعزل العرض وحده، وهذه الدالّة تستقبل
        // القيد بربط النموذج فتُحدّثه بلا سؤال — فمعرّفٌ رقميّ يكفي لاعتماد ملخّص
        // استشارةٍ غير مسندة إلى المُقرِّر وإطلاقه إلى عميلها. وقبل `validate` كي لا
        // تُسرّب رسائلُ التحقّق شيئاً عن قيدٍ لا يملك صاحبُ الطلب رؤيته أصلاً.
        abort_unless(AiReviewInbox::mayDecide($request->user(), $run), 403);

        $data = $request->validate([
            'action' => ['required', Rule::in(array_column(AiReviewAction::cases(), 'value'))],
            'reason' => ['nullable', Rule::in(array_column(AiReviewReason::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:2000'],
            'escalated_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $action = AiReviewAction::from($data['action']);

        if ($action->requiresReason() && empty($data['reason'])) {
            return back()->withErrors(['reason' => 'الرفض يلزمه سبب منظَّم — يتحوّل إلى بيانات تقييم لا ملاحظة ضائعة.']);
        }

        if ($action->requiresAssignee() && empty($data['escalated_to'])) {
            return back()->withErrors(['escalated_to' => 'التصعيد يلزمه مُصعَّدٌ إليه.']);
        }

        // المُصعَّد إليه من القائمة المعروضة نفسها — لا عميل ولا حساب موقوف ولا المُصعِّد ذاته
        if ($action->requiresAssignee() && ! AiReviewInbox::mayEscalateTo($request->user(), (int) $data['escalated_to'])) {
            return back()->withErrors(['escalated_to' => 'لا يُصعَّد إلّا إلى محامٍ أو إداريٍّ فعّال غيرك.']);
        }

        // حقلٌ يخصّ التصعيد وحده: قرارٌ آخر لا يحمل مُصعَّداً إليه ولو أُرسل خطأً
        if (! $action->requiresAssignee()) {
            $data['escalated_to'] = null;
        }

        // القرار كما وقع لا كما أُعلن: «قبول» على نصٍّ حرّره المراجع **تعديلٌ**.
        // بهذا يصير `humanEditRate` قياساً لعملٍ لا استفتاءً على نيّة.
        [$action, $editDistance] = AiReviewOutcome::effectiveAction($run, $action);

        // القرار وأثره معاً أو لا شيء: إن رفض المحرّك الأثر (نشر نتيجة جلسةٍ لتذكرةٍ حُوّلت
        // قضيّةً مثلاً) لا يبقى القيد «مقبولاً» ويغيب من الصندوق والملفُّ لم يُطلَق.
        DB::transaction(function () use ($run, $action, $data, $editDistance, $request) {
            $run->update([
                'review_action' => $action->value,
                'review_reason' => $data['reason'] ?? null,
                'review_note' => $data['note'] ?? null,
                'review_edit_distance' => $editDistance,
                'escalated_to' => $data['escalated_to'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            // أثر القرار في الملفّ — لا في `ai_runs` وحده. القبول على مخرجٍ محجوبٍ عن
            // العميل بانتظار اعتماد يجب أن يُطلقه، وإلّا بقي محجوباً وإن اعتُمد.
            AiReviewOutcome::apply($run->fresh(), $action, $request->user());
        });

        // التصعيد تسليمٌ لإنسان — يُبلَّغ به، وإلّا انتظر القيدُ في صندوقٍ لا يعلم صاحبه أنه فيه
        if ($action === AiReviewAction::Escalate) {
            Notify::send(
                (int) $data['escalated_to'],
                'alert',
                't-amber',
                "صعّد {$request->user()->name} إليك مخرج ذكاء للمراجعة: ".AiPromptRegistry::taskLabel($run->task_type)
                    .($run->entity_ref ? " — {$run->entity_ref}" : '').' — تجده في «مراجعة مخرجات الذكاء».',
            );
        }

        return back()->with('flash', "سُجّل القرار: {$action->label()}");
    }
}
