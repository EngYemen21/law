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

        // متزامن: المحامي ينتظر المسودة؛ set_time_limit داخل run() يحمي من مهلة الويب
        $draft = $this->ai->assist($data['kind'], $data['docType'], $data['ref'] ?? null, $data['context'] ?? '');

        return response()->json(['draft' => $draft]);
    }
}
