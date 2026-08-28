<?php

namespace App\Http\Controllers\Lawyer;

use App\Events\CaseStatusBroadcast;
use App\Http\Controllers\Concerns\ScopedToLawyer;
use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeCaseDocumentJob;
use App\Mail\HearingEventMail;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Services\MailService;
use App\Support\Audit;
use App\Support\CaseJourney;
use App\Support\ExecutionCreation;
use App\Support\Live;
use App\Support\MeetingTime;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحة المحامي للقضايا — التحضير واعتماد اللائحة وجدولة الجلسات وتسجيل الحكم.
 */
class CaseController extends Controller
{
    use ScopedToLawyer;

    public function index(Request $request): Response
    {
        $cases = LegalCase::with('user')->whereNotNull('ticket_id')
            ->where('assigned_lawyer_id', $request->user()->id)->latest('id')->get()
            ->map(fn (LegalCase $c) => $this->card($c));

        return Inertia::render('lawyer/cases', ['cases' => $cases]);
    }

    public function show(LegalCase $case): Response
    {
        $this->guardAssigned($case);
        $case->load(['user', 'hearings']);

        return Inertia::render('lawyer/case', [
            'case' => $this->card($case),
            'channel' => 'case.'.$case->id,
            'messages' => $case->messages->where('who', '!=', 'note')->values()->map->toMessage(),
            'hearings' => $case->hearings->map->toData(),
            'documents' => $case->documents->map(fn ($d) => $d->toData(auth()->user())),
            'convertedExec' => $case->execution()->exists(),
        ]);
    }

    // ردّ المحامي المسؤول على موكّله داخل ملفّ القضية (نظير رد الموظف) — بثّ لحظي.
    // كان المحامي يقرأ المحادثة ولا يملك وسيلة للردّ، فيضطر للخروج لقناة أخرى.
    public function reply(Request $request, LegalCase $case): \Illuminate\Http\Response
    {
        $this->guardAssigned($case);
        $data = $request->validate(['body' => ['required', 'string']]);

        $msg = $case->messages()->create([
            'who' => 'lawyer',
            'name' => $request->user()->name,
            'role' => 'المستشار القانوني',
            'body' => nl2br(e($data['body'])),
            'time_label' => now()->format('h:i').' '.(now()->hour < 12 ? 'ص' : 'م'),
        ]);
        // البثّ يقع تلقائياً في CaseMessage::booted عند الإنشاء — لا تُكرّره هنا

        return response()->noContent();
    }

    // إرفاق مستند حقيقي من المحامي إلى ملف القضية (مذكرات/أدلة/مسودات) — يُحفظ ضمن مستندات القضية.
    public function attach(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardAssigned($case);
        abort_if($case->status === 'مؤرشفة', 422, 'لا يمكن إرفاق مستندات على قضية مؤرشفة.');

        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx']]); // حتى 10MB

        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $doc = $case->documents()->create([
            'name' => $name,
            'path' => $file->store("case-docs/{$case->id}"),
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => 'lawyer',
            'status' => 'قيد الفحص',
        ]);

        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'مستند',
            'body' => '<p>أرفق المحامي مستنداً بملف القضية:</p><div class="doc-list"><span class="doc-chip">📎 '.e($name).'</span></div>',
            'time_label' => $this->clock(),
        ]);
        $case->update(['update_text' => 'أرفق المحامي مستنداً: '.$name]);
        $this->notify($case, 'upload', 't-cyan', "أُرفق مستند جديد بملف قضيتك {$case->number}.");

        // تحليل ذكي للمستند بالخلفية (تلخيص + تصنيف ثم ملخّص في المحادثة)
        AnalyzeCaseDocumentJob::dispatch($case, $doc);

        return back();
    }

    // فتح طلب تنفيذ من قضية بلغت «صدر الحكم» (تنفيذ الحكم)
    public function convertToExecution(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardAssigned($case);
        abort_unless(ExecutionCreation::isEligible($case), 422);

        ExecutionCreation::fromCase($case, $request->user());

        return redirect()->route('lawyer.execs');
    }

    // اعتماد اللائحة → القضية منظورة (يطابق cfApprove → cfTrack)
    public function approvePleading(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardAssigned($case);
        abort_unless($case->pleading_status === 'pending_lawyer', 422);

        $case->update([
            'pleading_status' => 'approved',
            'status' => 'منظورة',
            'tone' => CaseJourney::toneFor('منظورة'),
            'update_text' => 'اعتماد اللائحة ورفع الدعوى — القضية منظورة',
        ]);
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'اعتماد اللائحة',
            'body' => '<p>تم اعتماد لائحة الدعوى، وهي جاهزة لرفع الدعوى ومتابعة الجلسات.</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($case, 'scale', 't-blue', "تم اعتماد لائحة قضيتك {$case->number} ورفع الدعوى. القضية الآن منظورة.");
        Audit::log(
            action: 'اعتماد لائحة دعوى',
            description: "اعتمد {$request->user()->name} لائحة الدعوى للقضية {$case->number} — القضية الآن منظورة.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['الحالة' => 'منظورة'],
        );
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // جدولة جلسة جديدة (يراها العميل في «الجلسة القادمة»)
    public function addHearing(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardAssigned($case);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'day' => ['required', 'string', 'max:60'],
            'time' => ['nullable', 'string', 'max:32'],
            'court' => ['nullable', 'string', 'max:120'],
        ]);

        // موعد حقيقي للجلسة (يمكّن التذكير) — يبقى null إذا كان اليوم نصًّا عربيًّا غير قابل للتحليل
        $startsAt = MeetingTime::parse($data['day'], $data['time'] ?? null);
        $dayLabel = $startsAt ? $startsAt->locale('ar')->translatedFormat('l d F Y') : $data['day'];

        $hearing = $case->hearings()->create($data + ['status' => 'مجدولة', 'starts_at' => $startsAt]);
        $case->update(['update_text' => 'تم جدولة جلسة: '.$data['title']]);
        $this->refreshNextHearing($case);
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'جلسة',
            'body' => '<p>تم تحديد موعد جلسة: <b>'.e($data['title']).'</b> — '.e($dayLabel).(isset($data['time']) ? ' · '.e($data['time']) : '').'.</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($case, 'cal', 't-cyan', "جلسة جديدة على قضيتك {$case->number}: {$dayLabel}.");
        $this->mailHearingEvent($case, $hearing, 'created');
        Audit::log(
            action: 'جدولة جلسة محكمة',
            description: "جدول {$request->user()->name} جلسة «{$data['title']}» للقضية {$case->number} — {$dayLabel}".(isset($data['time']) ? " · {$data['time']}" : '').'.',
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['الجلسة' => $data['title'], 'الموعد' => $dayLabel.(isset($data['time']) ? ' · '.$data['time'] : '')],
        );
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    /** يعيد اشتقاق «الجلسة القادمة» من أقرب جلسة مجدولة (بالموعد الحقيقي إن وُجد، وإلا نصّها). */
    private function refreshNextHearing(LegalCase $case): void
    {
        // الفائتة (starts_at ماضٍ) ليست «قادمة» — كانت جلسة الشهر الماضي تبقى معروضة قادمةً
        $next = $case->hearings()->where('status', 'مجدولة')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()))
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at')->orderBy('id')
            ->first();

        $label = '—';
        if ($next) {
            $label = $next->starts_at
                ? trim($next->starts_at->locale('ar')->translatedFormat('l d F Y').($next->time ? ' · '.$next->time : ''))
                : trim((string) $next->day.($next->time ? ' · '.$next->time : ''));
        }

        $case->update(['next_hearing' => $label ?: '—']);
    }

    /** بريد بحدث الجلسة (إنشاء/إعادة جدولة/إلغاء) للعميل والمحامي — best-effort عبر MailService. */
    private function mailHearingEvent(LegalCase $case, CaseHearing $hearing, string $event): void
    {
        $mail = app(MailService::class);
        if ($case->user) {
            $mail->send($case->user, new HearingEventMail($hearing, $event));
        }
        if ($case->assignedLawyer) {
            $mail->send($case->assignedLawyer, new HearingEventMail($hearing, $event));
        }
    }

    // تسجيل نتيجة جلسة
    public function recordHearing(Request $request, LegalCase $case, CaseHearing $hearing): RedirectResponse
    {
        $this->guardAssigned($case);
        abort_unless($hearing->case_id === $case->id, 404);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:منعقدة,مؤجلة'],
            'outcome' => ['nullable', 'string', 'max:2000'],
        ]);
        $hearing->update($data);
        $case->update(['update_text' => 'تحديث جلسة: '.$hearing->title.' — '.$data['status']]);
        $this->refreshNextHearing($case); // المنعقدة/المؤجلة تخرج من «القادمة»

        $outcome = trim((string) ($data['outcome'] ?? ''));
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'جلسة',
            'body' => '<p>تحديث الجلسة «'.e($hearing->title).'»: <b>'.e($data['status']).'</b>'.($outcome !== '' ? ' — '.e($outcome) : '').'.</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($case, 'cal', $data['status'] === 'منعقدة' ? 't-green' : 't-amber', "تحديث جلسة قضيتك {$case->number}: {$hearing->title} — {$data['status']}.");
        Audit::log(
            action: 'تسجيل نتيجة جلسة',
            description: "سجّل {$request->user()->name} نتيجة الجلسة «{$hearing->title}» للقضية {$case->number}: {$data['status']}".($outcome !== '' ? " — {$outcome}" : '').'.',
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['الجلسة' => $hearing->title, 'الحالة' => $data['status']],
        );
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // تعديل/إعادة جدولة جلسة (نفس السجلّ) — يعيد ضبط الموعد ويصفّر أختام التذكير فتُعاد التذكيرات
    public function updateHearing(Request $request, LegalCase $case, CaseHearing $hearing): RedirectResponse
    {
        $this->guardAssigned($case);
        abort_unless($hearing->case_id === $case->id, 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'day' => ['required', 'string', 'max:60'],
            'time' => ['nullable', 'string', 'max:32'],
            'court' => ['nullable', 'string', 'max:120'],
        ]);

        $startsAt = MeetingTime::parse($data['day'], $data['time'] ?? null);
        $dayLabel = $startsAt ? $startsAt->locale('ar')->translatedFormat('l d F Y') : $data['day'];

        $hearing->update($data + [
            'status' => 'مجدولة', // إعادة الجدولة تعيد الجلسة لحالة مجدولة
            'starts_at' => $startsAt,
            'reminder_24h_sent_at' => null, // تصفير الأختام لإعادة التذكير للموعد الجديد
            'reminder_1h_sent_at' => null,
        ]);
        $case->update(['update_text' => 'إعادة جدولة جلسة: '.$data['title']]);
        $this->refreshNextHearing($case);
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'جلسة',
            'body' => '<p>أُعيدت جدولة الجلسة: <b>'.e($data['title']).'</b> — '.e($dayLabel).(isset($data['time']) ? ' · '.e($data['time']) : '').'.</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($case, 'cal', 't-cyan', "أُعيدت جدولة جلسة قضيتك {$case->number}: {$dayLabel}.");
        $this->mailHearingEvent($case, $hearing->fresh(), 'rescheduled');
        Audit::log(
            action: 'إعادة جدولة جلسة محكمة',
            description: "أعاد {$request->user()->name} جدولة الجلسة «{$data['title']}» للقضية {$case->number} — {$dayLabel}".(isset($data['time']) ? " · {$data['time']}" : '').'.',
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['الجلسة' => $data['title'], 'الموعد الجديد' => $dayLabel.(isset($data['time']) ? ' · '.$data['time'] : '')],
        );
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // إلغاء جلسة (سجلّ تاريخيّ بحالة «ملغاة») — تخرج من «القادمة» ولا تُذكَّر
    public function cancelHearing(Request $request, LegalCase $case, CaseHearing $hearing): RedirectResponse
    {
        $this->guardAssigned($case);
        abort_unless($hearing->case_id === $case->id, 404);

        $hearing->update(['status' => 'ملغاة']);
        $case->update(['update_text' => 'إلغاء جلسة: '.$hearing->title]);
        $this->refreshNextHearing($case);
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'جلسة',
            'body' => '<p>أُلغيت الجلسة: <b>'.e($hearing->title).'</b>.</p>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($case, 'cal', 't-amber', "أُلغيت جلسة على قضيتك {$case->number}: {$hearing->title}.");
        $this->mailHearingEvent($case, $hearing->fresh(), 'cancelled');
        Audit::log(
            action: 'إلغاء جلسة محكمة',
            description: "ألغى {$request->user()->name} الجلسة «{$hearing->title}» للقضية {$case->number}.",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
        );
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // تسجيل الحكم → بانتظار إغلاق الإدارة (يطابق ما قبل cfCloseCase)
    public function recordRuling(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardAssigned($case);
        // لا يُسجَّل حكم إلا وقضية منظورة (بعد اعتماد اللائحة ورفع الدعوى)، ولا يُسجَّل مرتين
        abort_unless($case->status === 'منظورة', 422);

        $data = $request->validate(['ruling' => ['required', 'string', 'max:3000']]);

        $case->update([
            'status' => 'صدر الحكم',
            'tone' => CaseJourney::toneFor('صدر الحكم'),
            'ruling' => $data['ruling'],
            'update_text' => 'صدر الحكم في القضية — بانتظار الإغلاق والأرشفة',
        ]);
        $case->messages()->create([
            'who' => 'lawyer', 'name' => $request->user()->name, 'role' => 'الحكم',
            'body' => '<p>صدر الحكم في القضية:</p><div class="result-card"><div class="result-sec"><div class="t">منطوق الحكم</div><div style="white-space:pre-line">'.e($data['ruling']).'</div></div></div>',
            'time_label' => $this->clock(),
        ]);
        $this->notify($case, 'scale', 't-green', "صدر الحكم في قضيتك {$case->number}. التفاصيل داخل القضية.");
        Audit::log(
            action: 'تسجيل حكم قضائي',
            description: "سجّل {$request->user()->name} صدور الحكم في القضية {$case->number}.",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['الحالة' => 'صدر الحكم'],
        );
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    private function card(LegalCase $c): array
    {
        return [
            'no' => $c->number,
            'client' => Ticket::maskClient($c->user?->name ?? ''),
            'type' => $c->type,
            'dept' => $c->department,
            'lawyer' => $c->assigned_lawyer ?: '—',
            'status' => $c->status,
            'tone' => $c->tone,
            'next' => $c->nextHearingLabel(),
            'pleadingStatus' => $c->pleading_status,
            'ruling' => $c->ruling,
        ];
    }

    private function notify(LegalCase $case, string $icon, string $tone, string $body): void
    {
        Notify::send($case->user_id, $icon, $tone, $body);
    }

    private function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
