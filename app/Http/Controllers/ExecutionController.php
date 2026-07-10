<?php

namespace App\Http\Controllers;

use App\Models\Execution;
use App\Services\LegalAiService;
use App\Support\AfterResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class ExecutionController extends Controller
{
    public function __construct(private LegalAiService $ai) {}

    // قائمة طلبات تنفيذ العميل الحالي
    public function index(Request $request): Response
    {
        $execs = Execution::where('user_id', $request->user()->id)->latest('id')->get()
            ->map(fn (Execution $e) => $e->toCard());

        return Inertia::render('execs', [
            'execs' => $execs,
        ]);
    }

    // إنشاء طلب تنفيذ مباشر من العميل
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);

        $details = trim($data['details'] ?? '') ?: ('طلب تنفيذ بخصوص: '.$data['subject']);
        $number = 'EXE-'.now()->year.'-'.random_int(1000, 9999);

        $exec = $request->user()->executions()->create([
            'number' => $number,
            'subject' => $data['subject'],
            'status' => 'جديد',
            'tone' => 'b-blue',
            'last_action' => 'فتح الطلب',
        ]);

        $exec->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => nl2br(e($details)), 'time_label' => $this->clock()]);
        $exec->messages()->create([
            'who' => 'ai', 'name' => LegalAiService::AGENT_NAME, 'role' => 'التنفيذ',
            'body' => 'تم استلام طلب التنفيذ، وسيتولّى قسم التنفيذ تجهيز السند التنفيذي ومتابعة الإجراءات لدى محكمة التنفيذ.',
            'time_label' => $this->clock(),
        ]);

        return redirect()->route('execs.show', $exec);
    }

    // متابعة طلب تنفيذ واحد
    public function show(Request $request, Execution $execution): Response
    {
        $this->authorizeExecution($request, $execution);
        $execution->load('procedures');

        return Inertia::render('execchat', [
            'exec' => $execution->toCard(),
            'channel' => 'exec.'.$execution->id,
            'messages' => $execution->messages->where('who', '!=', 'note')->values()->map->toMessage(),
            'procedures' => $execution->procedures->map->toData(),
        ]);
    }

    // إرسال رسالة من العميل + ردّ فريق التنفيذ الحقيقي (AI) — بثّ لحظي
    public function storeMessage(Request $request, Execution $execution): HttpResponse
    {
        $this->authorizeExecution($request, $execution);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $execution->messages()->create([
            'who' => 'client', 'name' => 'أنت', 'role' => 'العميل',
            'body' => e($data['body']), 'time_label' => $this->clock(),
        ]);

        // ردّ فريق التنفيذ عبر AI بعد إرسال الاستجابة (يصل بالبث اللحظي عبر hook الرسائل)
        $body = $data['body'];
        AfterResponse::defer(function () use ($execution, $body) {
            $aiText = app(LegalAiService::class)->execReply($execution, $body)
                ?? 'تم استلام رسالتك بخصوص طلب التنفيذ، وسيوافيك قسم التنفيذ بالمستجدات في أقرب وقت.';
            $execution->messages()->create([
                'who' => 'ai', 'name' => LegalAiService::AGENT_NAME, 'role' => 'التنفيذ',
                'body' => nl2br(e($aiText)), 'time_label' => $this->clock(),
            ]);
        });

        return response()->noContent();
    }

    private function authorizeExecution(Request $request, Execution $execution): void
    {
        abort_unless($execution->user_id === $request->user()->id, 403);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
