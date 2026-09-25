<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Journey\Enums\HearingStatus;
use App\Domain\Journey\Enums\RescheduleReason;
use App\Domain\Journey\Transitions\LegalCase\RecordRuling as RecordRulingTransition;
use App\Domain\Journey\Workflow;
use App\Events\CaseStatusBroadcast;
use App\Mail\HearingEventMail;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\MailService;
use App\Support\Audit;
use App\Support\Booking\BookingMoved;
use App\Support\CaseFiling;
use App\Support\Live;
use App\Support\MeetingTime;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **إجراءات المحكمة على القضيّة** — رفعها في ناجز وقيدها، وجدولة جلساتها وتحديثها، وتسجيل الحكم.
 *
 * مصدرٌ واحد يناديه المحامي المسنَد والموظّف (قرار المالك 2026-09-11): الحرّاس والتحقّق والإشعارات
 * والتدقيق نفسها. يختلف الطرفان في أمرين فقط: مَن يُجاز (`guardCourtAccess`)، واسم الكاتب في
 * المحادثة (`CaseFiling::author`). وما يسجّله غير المحامي المسنَد يُبلَّغ به المحامي.
 */
trait ManagesCourtProceedings
{
    /** مَن يُجاز على هذه القضيّة — المحامي: المسنَد إليه؛ الموظّف: صلاحيّة المسار. */
    abstract protected function guardCourtAccess(LegalCase $case): void;

    /**
     * **مَن يسجّل الحكم** — الحكم وتصحيحه وحكم الاستئناف. الأصل هو حارس الإجراءات نفسه
     * (المحامي المسنَد)، والموظّف يُضيّقه بصلاحيّة «تسجيل الأحكام» (قرار المالك 2026-09-18).
     */
    protected function guardRulingAccess(LegalCase $case): void
    {
        $this->guardCourtAccess($case);
    }

    /**
     * **تسجيل رفع الدعوى في ناجز** — رقم الطلب وتاريخه ⇐ «بانتظار القيد». (الخطّة ب — 2026-09-11)
     * وتكراره قبل القيد تصحيحٌ للبيانات لا رفعٌ ثانٍ.
     */
    public function fileNajiz(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardCourtAccess($case);
        $blocked = CaseFiling::fileBlock($case);
        abort_if($blocked !== null, 422, (string) $blocked);

        $data = $request->validate([
            'request_no' => ['required', 'string', 'max:60'],
            'filed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'request_no.required' => 'أدخل رقم الطلب كما صدر من ناجز.',
            'filed_at.required' => 'أدخل تاريخ الرفع.',
            'filed_at.before_or_equal' => 'تاريخ الرفع لا يكون في المستقبل.',
        ]);

        $requestNo = trim($data['request_no']);
        CaseFiling::file($case, $request->user(), $requestNo, $data['filed_at']);
        $this->tellLawyer($case, $request->user(), "سُجّل رفع الدعوى في ناجز برقم الطلب {$requestNo}");

        return back()->with('flash', 'سُجّل رفع الدعوى في ناجز — القضيّة بانتظار القيد.');
    }

    /**
     * **تسجيل قيد الدعوى** — رقم القضيّة والمحكمة والدائرة وتاريخ القيد ⇐ «منظورة»، وتُجدوَل
     * الجلسة الأولى بتذكيراتها عبر مسار الجدولة نفسه، وتُحفظ صورة الصحيفة المقيّدة إن أُرفقت.
     */
    public function registerNajiz(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardCourtAccess($case);
        $blocked = CaseFiling::registerBlock($case);
        abort_if($blocked !== null, 422, (string) $blocked);

        $data = $request->validate([
            'case_no' => ['required', 'string', 'max:60'],
            'court' => ['required', 'string', 'max:160'],
            'circuit' => ['required', 'string', 'max:160'],
            'registered_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'hearing_day' => ['required', 'date_format:Y-m-d', 'after_or_equal:registered_at'],
            'hearing_time' => ['required', 'date_format:H:i'],
            'hearing_mode' => ['required', 'in:حضورية,عن بُعد'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'case_no.required' => 'أدخل رقم القضية كما صدر من ناجز.',
            'circuit.required' => 'أدخل اسم الدائرة القضائية.',
            'hearing_day.after_or_equal' => 'موعد الجلسة الأولى لا يسبق تاريخ القيد.',
        ]);

        $actor = $request->user();
        CaseFiling::register($case, $actor, $data);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $case->documents()->create([
                'name' => $file->getClientOriginalName(),
                'path' => $file->store("case-docs/{$case->id}"),
                'mime' => $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
                'uploaded_by' => CaseFiling::author($actor),
                'status' => 'مرفق',
                'doc_type' => 'صحيفة الدعوى المقيّدة',
                // ملخّصٌ من بيانات القيد — ليس مستنداً «بانتظار التحليل»
                'summary' => "صحيفة الدعوى بعد قيدها في ناجز — رقم القضية {$data['case_no']}، {$data['court']} — {$data['circuit']}.",
            ]);
        }

        $this->createHearing($case->fresh(), $actor, [
            'title' => 'الجلسة الأولى — '.$data['hearing_mode'],
            'day' => $data['hearing_day'],
            'time' => $data['hearing_time'],
            'court' => $data['circuit'],
        ]);
        $this->tellLawyer($case, $actor, "قُيّدت الدعوى برقم {$data['case_no']} وجُدولت الجلسة الأولى");

        return back()->with('flash', "سُجّل قيد الدعوى برقم {$data['case_no']} وجُدولت الجلسة الأولى.");
    }

    // جدولة جلسة جديدة (يراها العميل في «الجلسة القادمة»)
    public function addHearing(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardCourtAccess($case);
        // كالواجهة: الجلسات بعد رفع الدعوى وقبل الحكم — كانت محروسةً في الشاشة وحدها
        abort_unless($case->status === 'منظورة', 422, 'تُضاف الجلسات والقضيّة منظورة — بعد اعتماد اللائحة ورفع الدعوى وقبل الحكم.');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'day' => ['required', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'court' => ['nullable', 'string', 'max:120'],
        ]);

        $this->createHearing($case, $request->user(), $data);
        $this->tellLawyer($case, $request->user(), "جُدولت جلسة «{$data['title']}»");

        return back();
    }

    // تسجيل نتيجة جلسة
    public function recordHearing(Request $request, LegalCase $case, CaseHearing $hearing): RedirectResponse
    {
        $this->guardCourtAccess($case);
        abort_unless($hearing->case_id === $case->id, 404);
        $this->guardHearingsOpen($case);
        abort_unless((bool) $hearing->statusEnum()?->awaitsOutcome(), 422, 'تُسجَّل نتيجةُ جلسةٍ مجدولة أو فائتة فقط.');
        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', HearingStatus::outcomes())],
            'outcome' => ['nullable', 'string', 'max:2000'],
        ]);
        $actor = $request->user();
        $hearing->update($data);
        $case->update(['update_text' => 'تحديث جلسة: '.$hearing->title.' — '.$data['status']]);
        $this->refreshNextHearing($case); // المنعقدة/المؤجلة تخرج من «القادمة»

        $outcome = trim((string) ($data['outcome'] ?? ''));
        $case->messages()->create([
            'who' => CaseFiling::author($actor), 'name' => $actor->name, 'role' => 'جلسة',
            'body' => '<p>تحديث الجلسة «'.e($hearing->title).'»: <b>'.e($data['status']).'</b>'.($outcome !== '' ? ' — '.e($outcome) : '').'.</p>',
            'time_label' => $this->courtClock(),
        ]);
        Notify::send($case->user_id, 'cal', HearingStatus::from($data['status']) === HearingStatus::Held ? 't-green' : 't-amber', "تحديث جلسة قضيتك {$case->number}: {$hearing->title} — {$data['status']}.");
        Audit::log(
            action: 'تسجيل نتيجة جلسة',
            description: "سجّل {$actor->name} نتيجة الجلسة «{$hearing->title}» للقضية {$case->number}: {$data['status']}".($outcome !== '' ? " — {$outcome}" : '').'.',
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['الجلسة' => $hearing->title, 'الحالة' => $data['status']],
        );
        $this->tellLawyer($case, $actor, "سُجّلت نتيجة الجلسة «{$hearing->title}»: {$data['status']}");
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    /**
     * **تعديل جلسة — وإن تغيّر موعدها فهو تأجيل.**
     *
     * كان التعديل يكتب فوق الصفّ نفسه: يضيع الموعد القديم، وجلسةٌ سُجّلت «مؤجلة» بنتيجتها تُمحى
     * نتيجتها وتعود «مجدولة». وفي المحكمة الجلسة المؤجّلة محضرٌ يبقى، والتالية جلسةٌ جديدة. فصار:
     * تغيُّر الموعد ⇐ تأجيلٌ بسببٍ إلزاميّ وصفٍّ جديد (`postponeHearing`)؛ وتعديل العنوان أو الدائرة
     * وحده ⇐ تصحيح بياناتٍ في مكانه (`editHearingDetails`) — لا يُقال للعميل «أُعيدت جدولة جلستك»
     * وموعده لم يتغيّر.
     */
    public function updateHearing(Request $request, LegalCase $case, CaseHearing $hearing): RedirectResponse
    {
        $this->guardCourtAccess($case);
        abort_unless($hearing->case_id === $case->id, 404);
        $this->guardHearingsOpen($case);
        $this->guardHearingEditable($hearing);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'day' => ['required', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'court' => ['nullable', 'string', 'max:120'],
        ]);
        $actor = $request->user();
        $startsAt = MeetingTime::parse($data['day'], $data['time'] ?? null);

        if (! $this->hearingMoves($hearing, $startsAt)) {
            return $this->editHearingDetails($case, $hearing, $actor, $data);
        }

        // السبب يُطلب حين يتحرّك الموعد وحده — تصحيح عنوانٍ لا سبب له
        $why = $request->validate(RescheduleReason::rules('hearing'));
        // الوقت محدّد ⇐ يُقارن بالآن؛ وبلا وقت ⇐ باليوم، فجلسةٌ «اليوم» بلا ساعة ليست ماضية
        abort_if($startsAt->lt(isset($data['time']) ? now() : today()), 422, 'لا يمكن تأجيل الجلسة إلى موعدٍ مضى.');

        return $this->postponeHearing($case, $hearing, $actor, $data, $startsAt, RescheduleReason::from($why['reason'])->describe($why['note'] ?? null));
    }

    /**
     * إلغاء جلسة (سجلّ تاريخيّ بحالة «ملغاة») — تخرج من «القادمة» ولا تُذكَّر.
     * وتُلغى الجلسة المنتظرة وحدها: المؤجّلة سجلٌّ لما جرى في المحكمة، وإلغاؤها يمحو تأجيلها.
     */
    public function cancelHearing(Request $request, LegalCase $case, CaseHearing $hearing): RedirectResponse
    {
        $this->guardCourtAccess($case);
        abort_unless($hearing->case_id === $case->id, 404);
        $this->guardHearingsOpen($case);
        abort_unless((bool) $hearing->statusEnum()?->awaitsOutcome(), 422, 'تُلغى الجلسة المجدولة أو الفائتة فقط — المنعقدة والمؤجّلة والملغاة سجلٌّ لما جرى.');
        $actor = $request->user();

        $hearing->update(['status' => HearingStatus::Cancelled->value]);
        $case->update(['update_text' => 'إلغاء جلسة: '.$hearing->title]);
        $this->refreshNextHearing($case);
        $case->messages()->create([
            'who' => CaseFiling::author($actor), 'name' => $actor->name, 'role' => 'جلسة',
            'body' => '<p>أُلغيت الجلسة: <b>'.e($hearing->title).'</b>.</p>',
            'time_label' => $this->courtClock(),
        ]);
        Notify::send($case->user_id, 'cal', 't-amber', "أُلغيت جلسة على قضيتك {$case->number}: {$hearing->title}.");
        $this->mailHearingEvent($case, $hearing->fresh(), 'cancelled');
        Audit::log(
            action: 'إلغاء جلسة محكمة',
            description: "ألغى {$actor->name} الجلسة «{$hearing->title}» للقضية {$case->number}.",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
        );
        $this->tellLawyer($case, $actor, "أُلغيت الجلسة «{$hearing->title}»");
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // تسجيل الحكم → بانتظار إغلاق الإدارة (يطابق ما قبل cfCloseCase)
    public function recordRuling(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardRulingAccess($case);

        $data = $request->validate(['ruling' => ['required', 'string', 'max:3000']]);
        $actor = $request->user();

        Workflow::run(new RecordRulingTransition, $case, $actor, [
            'ruling' => $data['ruling'],
        ]);

        $case->messages()->create([
            'who' => CaseFiling::author($actor), 'name' => $actor->name, 'role' => 'الحكم',
            'body' => '<p>صدر الحكم في القضية:</p><div class="result-card"><div class="result-sec"><div class="t">منطوق الحكم</div><div style="white-space:pre-line">'.e($data['ruling']).'</div></div></div>',
            'time_label' => $this->courtClock(),
        ]);

        Notify::send($case->user_id, 'scale', 't-green', "صدر الحكم في قضيتك {$case->number}. التفاصيل داخل القضية.");
        Audit::log(
            action: 'تسجيل حكم قضائي',
            description: "سجّل {$actor->name} صدور الحكم في القضية {$case->number}.",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: ['الحالة' => 'منظورة'],
            afterState: ['الحالة' => 'صدر الحكم'],
        );
        $this->tellLawyer($case, $actor, 'سُجّل صدور الحكم');
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    // تصحيح منطوق الحكم القضائي أو إضافة قرار تفسير (المرحلة ب — 2026-09-16)
    public function correctRuling(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardRulingAccess($case);
        abort_unless(in_array($case->status, ['صدر الحكم', 'مغلقة'], true), 422, 'لا يُصحّح الحكم إلا بعد صدوره وقبل الأرشفة.');

        $data = $request->validate([
            'ruling' => ['required', 'string', 'max:3000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $actor = $request->user();

        $oldRuling = '';
        DB::transaction(function () use ($case, $data, $actor, &$oldRuling) {
            $locked = LegalCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['صدر الحكم', 'مغلقة'], true), 422, 'لا يُصحّح الحكم إلا بعد صدوره وقبل الأرشفة.');

            $oldRuling = (string) $locked->ruling;

            $locked->update([
                'ruling' => $data['ruling'],
                'update_text' => 'تم تصحيح منطوق الحكم — '.Str::limit($data['reason'], 60),
            ]);
            $locked->messages()->create([
                'who' => CaseFiling::author($actor), 'name' => $actor->name, 'role' => 'تصحيح الحكم',
                'body' => '<p>تم تصحيح منطوق الحكم القضائي بموجب قرار تصحيح/تفسير:</p>'
                    .'<div class="result-card"><div class="result-sec">'
                    .'<div class="t">سبب التصحيح</div><div>'.e($data['reason']).'</div>'
                    .'<div class="t" style="margin-top:8px">المنطوق المصحح</div><div style="white-space:pre-line">'.e($data['ruling']).'</div>'
                    .'</div></div>',
                'time_label' => $this->courtClock(),
            ]);
        });

        $case->refresh();
        Notify::send($case->user_id, 'scale', 't-amber', "تم تحديث منطوق الحكم في قضيتك {$case->number}.");
        Audit::log(
            action: 'تصحيح حكم قضائي',
            description: "صحّح {$actor->name} منطوق الحكم في القضية {$case->number} — السبب: {$data['reason']}.",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: ['منطوق_الحكم' => $oldRuling],
            afterState: ['منطوق_الحكم' => $data['ruling'], 'سبب_التصحيح' => $data['reason']],
        );
        $this->tellLawyer($case, $actor, 'تم تصحيح منطوق الحكم');
        Live::push(new CaseStatusBroadcast($case));

        return back()->with('flash', 'تم تصحيح منطوق الحكم بنجاح.');
    }

    // تسجيل قيد لائحة الاستئناف (المرحلة د — 2026-09-16)
    public function recordAppeal(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardCourtAccess($case);
        abort_unless(in_array($case->status, ['صدر الحكم', 'مغلقة'], true), 422, 'لا يُسجَّل الاستئناف إلا بعد صدور الحكم وقبل الأرشفة.');

        $data = $request->validate([
            'appeal_request_no' => ['required', 'string', 'max:64'],
            'appeal_court' => ['required', 'string', 'max:160'],
            'appeal_circuit' => ['required', 'string', 'max:160'],
            'appeal_filed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $actor = $request->user();

        DB::transaction(function () use ($case, $data, $actor) {
            $locked = LegalCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['صدر الحكم', 'مغلقة'], true), 422, 'لا يُسجَّل الاستئناف إلا بعد صدور الحكم وقبل الأرشفة.');

            $locked->update([
                'appeal_status' => 'appeal_filed',
                'appeal_request_no' => $data['appeal_request_no'],
                'appeal_court' => $data['appeal_court'],
                'appeal_circuit' => $data['appeal_circuit'],
                'appeal_filed_at' => $data['appeal_filed_at'],
                'update_text' => 'قُيّد طلب الاستئناف برقم '.$data['appeal_request_no'].' لدى '.$data['appeal_court'],
            ]);

            $locked->messages()->create([
                'who' => CaseFiling::author($actor), 'name' => $actor->name, 'role' => 'استئناف',
                'body' => '<p>سُجّل قيد اللائحة الاعتراضية وطلب الاستئناف:</p>'
                    .'<div class="result-card"><div class="result-sec">'
                    .'<div class="t">رقم طلب الاستئناف</div><div>'.e($data['appeal_request_no']).'</div>'
                    .'<div class="t" style="margin-top:6px">المحكمة والدائرة</div><div>'.e($data['appeal_court']).' — '.e($data['appeal_circuit']).'</div>'
                    .'<div class="t" style="margin-top:6px">تاريخ القيد</div><div>'.e($data['appeal_filed_at']).'</div>'
                    .'</div></div>',
                'time_label' => $this->courtClock(),
            ]);
        });

        $case->refresh();
        Notify::send($case->user_id, 'scale', 't-blue', "سُجّل قيد طلب الاستئناف لقضيتك {$case->number} لدى {$data['appeal_court']}.");
        Audit::log(
            action: 'تسجيل طلب استئناف',
            description: "سجّل {$actor->name} طلب استئناف للقضية {$case->number} برقم {$data['appeal_request_no']} لدى {$data['appeal_court']}.",
            category: 'قضايا وتنفيذ',
            severity: 'info',
            auditable: $case,
            auditableRef: $case->number,
            afterState: [
                'حالة_الاستئناف' => 'appeal_filed',
                'رقم_طلب_الاستئناف' => $data['appeal_request_no'],
                'محكمة_الاستئناف' => $data['appeal_court'],
                'دائرة_الاستئناف' => $data['appeal_circuit'],
                'تاريخ_القيد' => $data['appeal_filed_at'],
            ],
        );
        $this->tellLawyer($case, $actor, 'سُجّل طلب الاستئناف');
        Live::push(new CaseStatusBroadcast($case));

        return back()->with('flash', 'سُجّل طلب الاستئناف بنجاح.');
    }

    // تسجيل حكم محكمة الاستئناف (المرحلة د — 2026-09-16)
    public function recordAppealRuling(Request $request, LegalCase $case): RedirectResponse
    {
        $this->guardRulingAccess($case);
        abort_unless(in_array($case->status, ['صدر الحكم', 'مغلقة'], true), 422, 'لا يُسجَّل حكم الاستئناف إلا بعد صدور الحكم وقبل الأرشفة.');
        abort_unless($case->appeal_status === 'appeal_filed', 422, 'يجب قيد طلب الاستئناف أولاً قبل تسجيل حكمه.');

        $data = $request->validate([
            'appeal_ruling' => ['required', 'string', 'max:3000'],
            'appeal_outcome' => ['required', 'in:تأييد الحكم الابتدائي,نقض الحكم,تعديل الحكم'],
            'appeal_judged_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $actor = $request->user();

        DB::transaction(function () use ($case, $data, $actor) {
            $locked = LegalCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['صدر الحكم', 'مغلقة'], true), 422, 'لا يُسجَّل حكم الاستئناف إلا بعد صدور الحكم وقبل الأرشفة.');
            abort_unless($locked->appeal_status === 'appeal_filed', 422, 'يجب قيد طلب الاستئناف أولاً قبل تسجيل حكمه.');

            $locked->update([
                'appeal_status' => 'appeal_judged',
                'appeal_ruling' => $data['appeal_outcome'].' — '.$data['appeal_ruling'],
                'appeal_judged_at' => $data['appeal_judged_at'],
                'update_text' => 'صدر قرار محكمة الاستئناف: '.$data['appeal_outcome'],
            ]);

            $locked->messages()->create([
                'who' => CaseFiling::author($actor), 'name' => $actor->name, 'role' => 'حكم الاستئناف',
                'body' => '<p>صدر قرار محكمة الاستئناف (<b>'.e($data['appeal_outcome']).'</b>):</p>'
                    .'<div class="result-card"><div class="result-sec">'
                    .'<div class="t">منطوق قرار الاستئناف</div><div style="white-space:pre-line">'.e($data['appeal_ruling']).'</div>'
                    .'<div class="t" style="margin-top:6px">تاريخ القرار</div><div>'.e($data['appeal_judged_at']).'</div>'
                    .'</div></div>',
                'time_label' => $this->courtClock(),
            ]);
        });

        $case->refresh();
        Notify::send($case->user_id, 'scale', 't-green', "صدر قرار محكمة الاستئناف في قضيتك {$case->number} ({$data['appeal_outcome']}).");
        Audit::log(
            action: 'تسجيل حكم استئناف',
            description: "سجّل {$actor->name} صدور قرار محكمة الاستئناف للقضية {$case->number} ({$data['appeal_outcome']}).",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
            afterState: [
                'حالة_الاستئناف' => 'appeal_judged',
                'قرار_الاستئناف' => $data['appeal_outcome'],
                'منطوق_الاستئناف' => $data['appeal_ruling'],
                'تاريخ_القرار' => $data['appeal_judged_at'],
            ],
        );
        $this->tellLawyer($case, $actor, 'سُجّل حكم الاستئناف');
        Live::push(new CaseStatusBroadcast($case));

        return back()->with('flash', 'سُجّل قرار محكمة الاستئناف بنجاح.');
    }

    /** جدولة جلسةٍ بتذكيراتها وإشعاراتها — يناديه `addHearing` وتسجيلُ القيد (الجلسة الأولى). */
    private function createHearing(LegalCase $case, User $actor, array $data): void
    {
        // موعد حقيقي للجلسة (يمكّن التذكير) — يبقى null إذا كان اليوم نصًّا عربيًّا غير قابل للتحليل
        $startsAt = MeetingTime::parse($data['day'], $data['time'] ?? null);
        $dayLabel = $startsAt ? $startsAt->locale('ar')->translatedFormat('l d F Y') : $data['day'];

        $hearing = $case->hearings()->create($data + ['status' => HearingStatus::Scheduled->value, 'starts_at' => $startsAt]);
        $case->update(['update_text' => 'تم جدولة جلسة: '.$data['title']]);
        $this->refreshNextHearing($case);
        $case->messages()->create([
            'who' => CaseFiling::author($actor), 'name' => $actor->name, 'role' => 'جلسة',
            'body' => '<p>تم تحديد موعد جلسة: <b>'.e($data['title']).'</b> — '.e($dayLabel).(isset($data['time']) ? ' · '.e($data['time']) : '').'.</p>',
            'time_label' => $this->courtClock(),
        ]);
        Notify::send($case->user_id, 'cal', 't-cyan', "جلسة جديدة على قضيتك {$case->number}: {$dayLabel}.");
        $this->mailHearingEvent($case, $hearing, 'created');
        Audit::log(
            action: 'جدولة جلسة محكمة',
            description: "جدول {$actor->name} جلسة «{$data['title']}» للقضية {$case->number} — {$dayLabel}".(isset($data['time']) ? " · {$data['time']}" : '').'.',
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            afterState: ['الجلسة' => $data['title'], 'الموعد' => $dayLabel.(isset($data['time']) ? ' · '.$data['time'] : '')],
        );
        Live::push(new CaseStatusBroadcast($case));
    }

    /** المنعقدة والملغاة انتهى أمرها — والحالة غير المعروفة تُرفض بسببٍ مقروء لا تُفترض. */
    private function guardHearingEditable(CaseHearing $hearing): void
    {
        abort_unless($hearing->statusEnum()?->isFinal() === false, 422, 'جلسةٌ منعقدة أو ملغاة لا تُعدَّل ولا تُعاد جدولتها.');
    }

    /**
     * هل يتحرّك الموعد؟ المقارنة باللحظة لا بنصّي اليوم والوقت (قد يُخزَّن «09:30» ويصل «09:30» بصيغةٍ
     * أخرى). وجلسةٌ قديمة بموعدٍ نصّيّ لا يُفكّ لا تُقارَن — فأيّ تاريخٍ يُدخَل لها موعدٌ جديد.
     */
    private function hearingMoves(CaseHearing $hearing, ?Carbon $startsAt): bool
    {
        return $hearing->starts_at === null || $startsAt === null || ! $hearing->starts_at->equalTo($startsAt);
    }

    /** تصحيح العنوان أو الدائرة والموعد باقٍ — في مكانه، بلا إشعارٍ ولا بريدٍ للعميل. */
    private function editHearingDetails(LegalCase $case, CaseHearing $hearing, User $actor, array $data): RedirectResponse
    {
        $before = ['الجلسة' => $hearing->title, 'الدائرة' => $hearing->court];
        $hearing->fill(Arr::only($data, ['title', 'court']));

        if (! $hearing->isDirty()) {
            return back();
        }

        $hearing->save();
        Audit::log(
            action: 'تعديل بيانات جلسة',
            description: "عدّل {$actor->name} بيانات الجلسة «{$hearing->title}» للقضية {$case->number} — الموعد باقٍ على {$hearing->label()}.",
            category: 'قضايا وتنفيذ',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: $before,
            afterState: ['الجلسة' => $hearing->title, 'الدائرة' => $hearing->court],
        );
        $this->tellLawyer($case, $actor, "عُدّلت بيانات الجلسة «{$hearing->title}»");
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    /**
     * **تأجيل الجلسة** — القديمة تبقى محضراً «مؤجلة» بسببها، والموعد الجديد جلسةٌ جديدة تشير إليها.
     *
     * والقديمة المسجّلة «مؤجلة» سلفاً (من `recordHearing` بنتيجتها) لا تُمسّ: هنا يُحدَّد موعد تاليتها
     * فقط. وأختام التذكير من `BookingMoved::markers` — المصدر الواحد لما يتبع موعداً تحرّك.
     */
    private function postponeHearing(LegalCase $case, CaseHearing $hearing, User $actor, array $data, Carbon $startsAt, string $reason): RedirectResponse
    {
        $from = $hearing->label();

        $next = DB::transaction(function () use ($case, $hearing, $data, $startsAt, $reason) {
            // القفل وإعادة الحارس داخل المعاملة: نقرتان متزامنتان كانتا ستُنشئان تاليتين لجلسةٍ واحدة
            $old = CaseHearing::whereKey($hearing->id)->lockForUpdate()->firstOrFail();
            $this->guardHearingEditable($old);
            abort_if($old->postponedTo()->exists(), 422, 'أُجّلت هذه الجلسة وحُدّد موعد تاليتها — عدّل موعد الجلسة التالية.');

            if ($old->statusEnum()?->awaitsOutcome()) {
                $old->update([
                    'status' => HearingStatus::Postponed->value,
                    'outcome' => trim((string) $old->outcome) !== '' ? $old->outcome : $reason,
                ]);
            }

            return $case->hearings()->create([
                'postponed_from_id' => $old->id,
                'title' => $data['title'],
                'court' => array_key_exists('court', $data) ? $data['court'] : $old->court,
                'day' => $data['day'],
                'time' => $data['time'] ?? null,
                'starts_at' => $startsAt,
                'status' => HearingStatus::Scheduled->value,
            ] + BookingMoved::markers($old));
        });

        $to = $next->label();
        $case->update(['update_text' => 'تأجيل جلسة: '.$next->title.' — '.$reason]);
        $this->refreshNextHearing($case);
        $case->messages()->create([
            'who' => CaseFiling::author($actor), 'name' => $actor->name, 'role' => 'جلسة',
            'body' => '<p>أُجّلت الجلسة «'.e($hearing->title).'» ('.e($from).') إلى: <b>'.e($next->title).'</b> — '.e($to).'.<br>السبب: '.e($reason).'.</p>',
            'time_label' => $this->courtClock(),
        ]);
        Notify::send($case->user_id, 'cal', 't-cyan', "أُجّلت جلسة قضيتك {$case->number} إلى {$to} — {$reason}.");
        $this->mailHearingEvent($case, $next, 'rescheduled');
        Audit::log(
            action: 'تأجيل جلسة محكمة',
            description: "أجّل {$actor->name} الجلسة «{$hearing->title}» للقضية {$case->number} من {$from} إلى {$to} — {$reason}.",
            category: 'قضايا وتنفيذ',
            severity: 'warning',
            auditable: $case,
            auditableRef: $case->number,
            beforeState: ['الجلسة' => $hearing->title, 'الموعد' => $from],
            afterState: ['الجلسة' => $next->title, 'الموعد الجديد' => $to, 'السبب' => $reason],
        );
        $this->tellLawyer($case, $actor, "أُجّلت الجلسة «{$hearing->title}» إلى {$to}");
        Live::push(new CaseStatusBroadcast($case));

        return back();
    }

    /** المغلقة والمؤرشفة للقراءة: لا تُحرَّك جلساتهما ولا يُشعَر العميل بجدولةٍ في ملفٍّ منتهٍ. */
    private function guardHearingsOpen(LegalCase $case): void
    {
        abort_if(in_array($case->status, ['مغلقة', 'مؤرشفة'], true), 422, 'القضيّة مغلقة أو مؤرشفة — جلساتها للقراءة فقط.');
    }

    /** يعيد اشتقاق «الجلسة القادمة» من أقرب جلسة مجدولة (بالموعد الحقيقي إن وُجد، وإلا نصّها). */
    private function refreshNextHearing(LegalCase $case): void
    {
        // الفائتة (starts_at ماضٍ) ليست «قادمة» — كانت جلسة الشهر الماضي تبقى معروضة قادمةً
        $next = $case->hearings()->where('status', HearingStatus::Scheduled->value)
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

    /** إجراءٌ سجّله غيرُ المحامي المسنَد (الموظّف أو الإدارة) — يُبلَّغ به المحامي كي لا يفاجئه في ملفّه. */
    private function tellLawyer(LegalCase $case, User $actor, string $what): void
    {
        $lawyerId = (int) ($case->assigned_lawyer_id ?? 0);

        if ($lawyerId === 0 || $lawyerId === (int) $actor->id) {
            return;
        }

        Notify::send($lawyerId, 'scale', 't-blue', "{$what} في القضية {$case->number} — سجّله {$actor->name}.");
    }

    private function courtClock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
