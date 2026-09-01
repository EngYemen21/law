<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Services\Ai\AiRunLogger;
use App\Services\LegalAiService;
use Illuminate\Database\Eloquent\Model;
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
        // ويُعاد الكيان منه لا لِيُهمَل: به يُنسب قيد `ai_runs` للملفّ فيبلغ صندوق محاميه.
        $entity = ! empty($data['ref']) ? $this->guardRef($request, $data['ref']) : null;

        // متزامن: المحامي ينتظر المسودة؛ set_time_limit داخل run() يحمي من مهلة الويب
        $result = $this->ai->assistResult($data['kind'], $data['docType'], $data['ref'] ?? null, $data['context'] ?? '');
        $meta = $result['meta'];

        // قيدٌ في سجلّ القرارات: `assistant.draft` مصنَّفة `high` وكانت تُنتج مذكّرات
        // ودفوعاً وعقوداً **بلا أثرٍ واحد** — لا كلفة ولا نموذج ولا مراجعة مطلوبة.

        AiRunLogger::log('assistant.draft', $result['source'], $meta, $entity, $data['ref'] ?? null);

        return response()->json([
            'draft' => $result['draft'],
            // الوسم يصل الشاشة: قالبٌ ثابت ومخرجُ نموذج لا يُقدَّمان بالشكل نفسه
            'source' => $result['source']->value,
            'sourceLabel' => $result['source']->label(),
        ]);
    }

    /**
     * يمنع (403) استعمال مرجع قضية/تذكرة مسندة لمحامٍ آخر.
     * مرجع لا يطابق أي سجلّ يمرّ: لا شيء يُقرأ منه (المساعد يعمل على context وحده)،
     * ومنعه كان يكسر الاستعمال المشروع بمرجع حرّ.
     */
    private function guardRef(Request $request, string $ref): ?Model
    {
        $user = $request->user();

        // تُجلب كاملةً لا بعمود واحد: الكيان يُنسب إليه قيد `ai_runs` فيبلغ صندوق
        // مراجعة صاحب الملفّ عبر `AiReviewInbox::ownedByLawyer`.
        $records = array_values(array_filter([
            LegalCase::where('number', $ref)->first(),
            Ticket::where('number', $ref)->first(),
        ]));

        if ($records === []) {
            return null; // مرجع حرّ لا يطابق سجلاً — لا شيء يُقرأ منه ولا يُنسب إليه
        }

        if ($user->isAdmin()) {
            return $records[0]; // الإدارة تتجاوز العزل لا النسبة
        }

        // يكفي أن يكون أحدهما مسنداً إليه — الشرط السابق كان يستوجب كليهما
        $mine = array_values(array_filter($records, fn ($r) => (int) $r->assigned_lawyer_id === (int) $user->id));

        abort_if($mine === [], 403, 'هذا المرجع غير مسند إليك.');

        return $mine[0];
    }
}
