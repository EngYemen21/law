<?php

namespace App\Http\Controllers\Lawyer;

use App\Events\ExecStatusBroadcast;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Models\Execution;
use App\Models\ExecutionProcedure;
use App\Models\Ticket;
use App\Models\UserNotification;
use App\Support\ExecJourney;
use App\Support\Live;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحة المحامي لطلبات التنفيذ — تجهيز السند، القيد لدى محكمة التنفيذ، الإجراءات، والإكمال.
 */
class ExecutionController extends Controller
{
    use ScopedToLawyer;

    public function index(Request $request): Response
    {
        $execs = Execution::with('user')->where('assigned_lawyer_id', $request->user()->id)
            ->latest('id')->get()->map(fn (Execution $e) => $this->card($e));

        return Inertia::render('lawyer/execs', ['execs' => $execs]);
    }

    public function show(Execution $execution): Response
    {
        $this->guardAssigned($execution);
        $execution->load(['user', 'procedures']);

        return Inertia::render('lawyer/exec', [
            'exec' => $this->card($execution),
            'channel' => 'exec.'.$execution->id,
            'messages' => $execution->messages->where('who', '!=', 'note')->values()->map->toMessage(),
            'procedures' => $execution->procedures->map->toData(),
        ]);
    }

    // تجهيز السند التنفيذي
    public function prepareInstrument(Request $request, Execution $execution): RedirectResponse
    {
        $this->guardAssigned($execution);
        abort_unless(in_array($execution->status, ['جديد', 'قيد الفتح'], true), 422);

        $execution->update(['status' => 'تجهيز السند التنفيذي', 'tone' => ExecJourney::toneFor('تجهيز السند التنفيذي'), 'last_action' => 'تجهيز السند التنفيذي']);
        $execution->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'التنفيذ',
            'body' => '<p>جارٍ تجهيز السند التنفيذي وتدقيق مستندات التنفيذ تمهيداً للقيد لدى محكمة التنفيذ.</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($execution, 'exec', 't-blue', "بدأ قسم التنفيذ تجهيز السند التنفيذي لطلبك {$execution->number}.");
        Live::push(new ExecStatusBroadcast($execution));

        return back();
    }

    // القيد لدى محكمة التنفيذ
    public function registerCourt(Request $request, Execution $execution): RedirectResponse
    {
        $this->guardAssigned($execution);
        abort_unless($execution->status === 'تجهيز السند التنفيذي', 422);
        $data = $request->validate(['court' => ['required', 'string', 'max:120']]);

        // كانت النغمة غائبة هنا وحدها، فتحتفظ الشارة بلون المرحلة السابقة
        $execution->update(['status' => 'مقيّد لدى محكمة التنفيذ', 'tone' => ExecJourney::toneFor('مقيّد لدى محكمة التنفيذ'), 'court' => $data['court'], 'last_action' => 'القيد لدى '.$data['court']]);
        $execution->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'التنفيذ',
            'body' => '<p>تم قيد طلب التنفيذ لدى <b>'.e($data['court']).'</b>، وبدء الإجراءات النظامية.</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($execution, 'exec', 't-cyan', "تم قيد طلب تنفيذك {$execution->number} لدى محكمة التنفيذ.");
        Live::push(new ExecStatusBroadcast($execution));

        return back();
    }

    // إضافة إجراء تنفيذ (حجز/تحصيل/إخطار)
    public function addProcedure(Request $request, Execution $execution): RedirectResponse
    {
        $this->guardAssigned($execution);
        abort_unless(in_array($execution->status, ['مقيّد لدى محكمة التنفيذ', 'جارٍ'], true), 422);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:حجز,تحصيل,إخطار,إجراء'],
            'detail' => ['nullable', 'string', 'max:2000'],
        ]);

        $execution->procedures()->create($data + ['status' => 'مجدول']);
        $execution->update(['status' => 'جارٍ', 'tone' => ExecJourney::toneFor('جارٍ'), 'last_action' => $data['title']]);
        $execution->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'إجراء',
            'body' => '<p>إجراء تنفيذ جديد: <b>'.e($data['title']).'</b> ('.e($data['type']).').</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($execution, 'exec', 't-blue', "إجراء تنفيذ جديد على طلبك {$execution->number}: {$data['title']}.");
        Live::push(new ExecStatusBroadcast($execution));

        return back();
    }

    // تسجيل نتيجة إجراء
    public function recordProcedure(Request $request, Execution $execution, ExecutionProcedure $procedure): RedirectResponse
    {
        $this->guardAssigned($execution);
        abort_unless($procedure->execution_id === $execution->id, 404);
        $data = $request->validate(['status' => ['required', 'string', 'in:منفّذ,مؤجل']]);
        $procedure->update($data);
        $execution->update(['last_action' => $procedure->title.' — '.$data['status']]);
        Live::push(new ExecStatusBroadcast($execution));

        return back();
    }

    // التحصيل والإغلاق
    public function complete(Request $request, Execution $execution): RedirectResponse
    {
        $this->guardAssigned($execution);
        abort_unless($execution->status === 'جارٍ', 422);

        $execution->update(['status' => 'مكتمل', 'tone' => ExecJourney::toneFor('مكتمل'), 'last_action' => 'تم التحصيل وإغلاق طلب التنفيذ']);
        $execution->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'التنفيذ',
            'body' => '<p>تم استكمال إجراءات التنفيذ والتحصيل، وإغلاق طلب التنفيذ بنجاح.</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($execution, 'check', 't-green', "اكتمل تنفيذ طلبك {$execution->number} والتحصيل بنجاح.");
        Live::push(new ExecStatusBroadcast($execution));

        return back();
    }

    private function card(Execution $e): array
    {
        return [
            'no' => $e->number,
            'client' => Ticket::maskClient($e->user?->name ?? ''),
            'subject' => $e->subject,
            'lawyer' => $e->assigned_lawyer ?: '—',
            'court' => $e->court,
            'status' => $e->status,
            'tone' => $e->tone,
            'last' => $e->last_action,
        ];
    }

    private function notify(Execution $e, string $icon, string $tone, string $body): void
    {
        UserNotification::create([
            'user_id' => $e->user_id, 'icon' => $icon, 'tone' => $tone,
            'body' => $body, 'time_label' => 'الآن', 'is_read' => false,
        ]);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
