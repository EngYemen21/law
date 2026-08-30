<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Services\Ai\AiOpsMetrics;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewInbox;
use App\Services\Ai\AiReviewReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'entityRef' => $run->entity_ref ?? '—',
                'source' => $run->source?->value,
                'sourceLabel' => $run->source?->label(),
                // null يُعرض «غير مقيسة» لا صفراً — لا يُدّعى قياسٌ لم يقع
                'confidence' => $run->confidence,
                'confidenceSignals' => $run->confidence_signals,
                // دليل تقليل البيانات أمام المراجع: كم معرّفاً مُوّه وكم غادر الخادم
                'outboundAudit' => $run->outbound_audit,
                'model' => $run->model ?? '—',
                'promptVersion' => $run->prompt_version ?? '—',
                'failureCode' => $run->failure_code,
                'traceId' => $run->trace_id,
                'createdAt' => $run->created_at?->locale('ar')->translatedFormat('d F Y · h:i A'),
            ])->values(),
            'actions' => AiReviewAction::options(),
            'reasons' => AiReviewReason::options(),
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

        $run->update([
            'review_action' => $action->value,
            'review_reason' => $data['reason'] ?? null,
            'review_note' => $data['note'] ?? null,
            'escalated_to' => $data['escalated_to'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('flash', "سُجّل القرار: {$action->label()}");
    }
}
