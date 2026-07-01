<?php

namespace App\Http\Controllers\Lawyer;

use App\Events\CaseStatusBroadcast;
use App\Http\Controllers\Controller;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحة المحامي للقضايا — التحضير واعتماد اللائحة وجدولة الجلسات وتسجيل الحكم.
 */
class CaseController extends Controller
{
    public function index(): Response
    {
        $cases = LegalCase::with('user')->whereNotNull('ticket_id')->latest('id')->get()
            ->map(fn (LegalCase $c) => $this->card($c));

        return Inertia::render('lawyer/cases', ['cases' => $cases]);
    }

    public function show(LegalCase $case): Response
    {
        $case->load(['user', 'hearings']);

        return Inertia::render('lawyer/case', [
            'case' => $this->card($case),
            'channel' => 'case.'.$case->id,
            'messages' => $case->messages->where('who', '!=', 'note')->values()->map->toMessage(),
            'hearings' => $case->hearings->map->toData(),
        ]);
    }

    // اعتماد اللائحة → القضية منظورة (يطابق cfApprove → cfTrack)
    public function approvePleading(Request $request, LegalCase $case): RedirectResponse
    {
        abort_unless($case->pleading_status === 'pending_lawyer', 422);

        $case->update([
            'pleading_status' => 'approved',
            'status' => 'منظورة',
            'tone' => 'b-blue',
            'update_text' => 'اعتماد اللائحة ورفع الدعوى — القضية منظورة',
        ]);
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'اعتماد اللائحة',
            'body' => '<p>تم اعتماد لائحة الدعوى، وهي جاهزة لرفع الدعوى ومتابعة الجلسات.</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($case, 'scale', 't-blue', "تم اعتماد لائحة قضيتك {$case->number} ورفع الدعوى. القضية الآن منظورة.");
        broadcast(new CaseStatusBroadcast($case));

        return back();
    }

    // جدولة جلسة جديدة (يراها العميل في «الجلسة القادمة»)
    public function addHearing(Request $request, LegalCase $case): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'day' => ['required', 'string', 'max:60'],
            'time' => ['nullable', 'string', 'max:32'],
            'court' => ['nullable', 'string', 'max:120'],
        ]);

        $case->hearings()->create($data + ['status' => 'مجدولة']);
        $case->update([
            'next_hearing' => $data['day'].($data['time'] ? ' · '.$data['time'] : ''),
            'update_text' => 'تم جدولة جلسة: '.$data['title'],
        ]);
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'جلسة',
            'body' => '<p>تم تحديد موعد جلسة: <b>'.e($data['title']).'</b> — '.e($data['day']).(isset($data['time']) ? ' · '.e($data['time']) : '').'.</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($case, 'cal', 't-cyan', "جلسة جديدة على قضيتك {$case->number}: {$data['day']}.");
        broadcast(new CaseStatusBroadcast($case));

        return back();
    }

    // تسجيل نتيجة جلسة
    public function recordHearing(Request $request, LegalCase $case, CaseHearing $hearing): RedirectResponse
    {
        abort_unless($hearing->case_id === $case->id, 404);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:منعقدة,مؤجلة'],
            'outcome' => ['nullable', 'string', 'max:2000'],
        ]);
        $hearing->update($data);
        $case->update(['update_text' => 'تحديث جلسة: '.$hearing->title.' — '.$data['status']]);

        return back();
    }

    // تسجيل الحكم → بانتظار إغلاق الإدارة (يطابق ما قبل cfCloseCase)
    public function recordRuling(Request $request, LegalCase $case): RedirectResponse
    {
        $data = $request->validate(['ruling' => ['required', 'string', 'max:3000']]);

        $case->update([
            'status' => 'صدر الحكم',
            'tone' => 'b-cyan',
            'ruling' => $data['ruling'],
            'update_text' => 'صدر الحكم في القضية — بانتظار الإغلاق والأرشفة',
        ]);
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'الحكم',
            'body' => '<p>صدر الحكم في القضية:</p><div class="result-card"><div class="result-sec"><div class="t">منطوق الحكم</div><div style="white-space:pre-line">'.e($data['ruling']).'</div></div></div>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($case, 'scale', 't-green', "صدر الحكم في قضيتك {$case->number}. التفاصيل داخل القضية.");
        broadcast(new CaseStatusBroadcast($case));

        return back();
    }

    private function card(LegalCase $c): array
    {
        return [
            'no' => $c->number,
            'client' => \App\Models\Ticket::maskClient($c->user?->name ?? ''),
            'type' => $c->type,
            'dept' => $c->department,
            'lawyer' => $c->assigned_lawyer ?: '—',
            'status' => $c->status,
            'tone' => $c->tone,
            'next' => $c->next_hearing,
            'pleadingStatus' => $c->pleading_status,
            'ruling' => $c->ruling,
        ];
    }

    private function notify(LegalCase $case, string $icon, string $tone, string $body): void
    {
        UserNotification::create([
            'user_id' => $case->user_id, 'icon' => $icon, 'tone' => $tone,
            'body' => $body, 'time_label' => 'الآن', 'is_read' => false,
        ]);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
