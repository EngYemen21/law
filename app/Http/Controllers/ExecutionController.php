<?php

namespace App\Http\Controllers;

use App\Models\Execution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExecutionController extends Controller
{
    // قائمة طلبات تنفيذ العميل الحالي
    public function index(Request $request): Response
    {
        $execs = Execution::where('user_id', $request->user()->id)->latest('id')->get()
            ->map(fn (Execution $e) => $e->toCard());

        return Inertia::render('execs', [
            'execs' => $execs,
        ]);
    }

    // متابعة طلب تنفيذ واحد
    public function show(Request $request, Execution $execution): Response
    {
        $this->authorizeExecution($request, $execution);

        return Inertia::render('execchat', [
            'exec' => $execution->toCard(),
            'messages' => $execution->messages->map->toMessage(),
        ]);
    }

    // إرسال رسالة من العميل + ردّ تلقائي من الفريق
    public function storeMessage(Request $request, Execution $execution): RedirectResponse
    {
        $this->authorizeExecution($request, $execution);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $execution->messages()->create([
            'who' => 'client',
            'name' => 'أنت',
            'role' => 'العميل',
            'body' => e($data['body']),
            'time_label' => $this->clock(),
        ]);

        // ردّ الفريق القانوني التلقائي (يطابق سلوك ctSend في الواجهة)
        $execution->messages()->create([
            'who' => 'ai',
            'name' => 'الفريق القانوني',
            'role' => 'متابعة',
            'body' => 'تم استلام رسالتك، وسيوافيكم القسم المختص بالرد في أقرب وقت.',
            'time_label' => $this->clock(),
        ]);

        return back();
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
