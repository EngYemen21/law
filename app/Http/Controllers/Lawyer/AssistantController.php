<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Services\LegalAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * المساعد القانوني الذكي للمحامي — يولّد اللوائح والمذكرات والتحليل والدفوع
 * عبر LegalAiService الحقيقي (مع احتياط قالبي)، على مراجع تذاكر/قضايا حقيقية.
 */
class AssistantController extends Controller
{
    public function __construct(private LegalAiService $ai) {}

    public function index(Request $request): Response
    {
        // مراجع حقيقية: قضايا المحامي وتذاكره المحالة
        $cases = LegalCase::where('assigned_lawyer_id', $request->user()->id)->latest('id')->pluck('number');
        $tickets = Ticket::whereHas('summary')->where('assigned_lawyer_id', $request->user()->id)->latest('id')->pluck('number');
        $refs = $cases->merge($tickets)->values();

        return Inertia::render('lawyer/assistant', ['refs' => $refs]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:lawahe,mems,analyze,defense,reply_memo,contract_check,strengths_weaknesses,qualification'],
            'docType' => ['required', 'string', 'max:80'],
            'ref' => ['nullable', 'string', 'max:60'],
            'context' => ['nullable', 'string', 'max:15000'],
        ]);

        // المرجع يُقرأ منه وقائع الملف وتوصياته — يُحرس بالإسناد وإلا قرأ المحامي ملفّات زملائه
        // بتخمين رقم تذكرة (SB-YYYY-NNNN قابل للعدّ). الإدارة مستثناة (صلاحيات مطلقة).
        if (! empty($data['ref'])) {
            $this->guardRef($request, $data['ref']);
        }

        // متزامن: المحامي ينتظر المسودة؛ set_time_limit داخل run() يحمي من مهلة الويب
        $draft = $this->ai->assist($data['kind'], $data['docType'], $data['ref'] ?? null, $data['context'] ?? '');

        return response()->json(['draft' => $draft]);
    }

    /**
     * يمنع (403) استعمال مرجع قضية/تذكرة مسندة لمحامٍ آخر.
     * مرجع لا يطابق أي سجلّ يمرّ: لا شيء يُقرأ منه (المساعد يعمل على context وحده)،
     * ومنعه كان يكسر الاستعمال المشروع بمرجع حرّ.
     */
    private function guardRef(Request $request, string $ref): void
    {
        $user = $request->user();
        if ($user->isAdmin()) {
            return;
        }

        $records = array_values(array_filter([
            LegalCase::where('number', $ref)->first(['assigned_lawyer_id']),
            Ticket::where('number', $ref)->first(['assigned_lawyer_id']),
        ]));

        if ($records === []) {
            return; // مرجع حرّ لا يطابق سجلاً — لا شيء يُقرأ منه
        }

        // يكفي أن يكون أحدهما مسنداً إليه — الشرط السابق كان يستوجب كليهما
        $mine = array_filter($records, fn ($r) => (int) $r->assigned_lawyer_id === (int) $user->id);

        abort_if($mine === [], 403, 'هذا المرجع غير مسند إليك.');
    }
}
