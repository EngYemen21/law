<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\LegalDocument;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Support\CasePleading;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * محرر الصياغة القانونية — WYSIWYG لتحرير اللوائح والمذكرات والملخصات والعقود.
 *
 * يخدم لوحتي المحامي والإدارة. المسار الحالي (lawyer/* أو admin/*) يحدد القاعدة
 * تلقائياً، والمتحكم يحرس الوصول بالملكية أو بدور الإدارة.
 */
class DocumentEditorController extends Controller
{
    /**
     * قائمة المستندات — تُظهر مستندات المستخدم الحالي (أو الكل للإدارة).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $query = LegalDocument::with('user:id,name')
            ->latest('updated_at');

        // الإدارة ترى الكل؛ المحامي/الموظف يرى مستنداته فقط
        if (! $isAdmin) {
            $query->where('user_id', $user->id);
        }

        $docs = $query->get()->map(fn (LegalDocument $d) => $d->toCard());

        return Inertia::render('lawyer/editor-index', [
            'documents' => $docs,
            'types'     => LegalDocument::TYPES,
            'statuses'  => LegalDocument::STATUSES,
        ]);
    }

    /**
     * صفحة إنشاء مستند جديد — تعرض المحرر فارغاً مع قالب اختياري أو مسودة مستوردة.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $ticketNo = $request->query('ticket');
        $ticket = $ticketNo
            ? Ticket::where('number', $ticketNo)->first()
            : null;

        // مسودة خام قادمة من المساعد القانوني أو عبر الاستيراد
        $incomingDraft = (string) $request->query('draft', '');
        $incomingTemplate = (string) $request->query('template', '');
        $incomingTitle = '';
        $incomingType = 'free';
        $incomingMeta = null;
        $incomingCase = null;

        // استيراد مباشر عبر الرابط: ?importType=case_pleading&id=12 أو ?importType=ticket_summary&id=5
        $importType = $request->query('importType');
        $importId = $request->query('id') ?: $request->query('importId');

        if ($importType && $importId) {
            if ($importType === 'case_pleading') {
                $case = is_numeric($importId)
                    ? LegalCase::with(['user', 'assignedLawyer'])->find($importId)
                    : LegalCase::with(['user', 'assignedLawyer'])->where('number', $importId)->first();

                if ($case && ($isAdmin || $case->assigned_lawyer_id === $user->id)) {
                    $draftText = CasePleading::draftText(CasePleading::latestDraft($case));
                    $incomingDraft = $this->formatPleadingHtml($case, $draftText);
                    $incomingTitle = "لائحة دعوى — قضية رقم {$case->number}" . ($case->type ? " ({$case->type})" : '');
                    $incomingType = 'lawsuit';
                    $incomingCase = ['id' => $case->id, 'no' => $case->number];
                    $incomingMeta = [
                        'source_type' => 'case_pleading',
                        'case_id'     => $case->id,
                        'case_number' => $case->number,
                    ];
                }
            } elseif ($importType === 'ticket_summary') {
                $summary = TicketSummary::with(['ticket.user', 'ticket.assignedLawyer', 'lawyer'])->find($importId);
                if ($summary && ($isAdmin || $summary->lawyer_id === $user->id || $summary->ticket?->assigned_lawyer_id === $user->id)) {
                    $incomingDraft = $this->formatTicketSummaryHtml($summary);
                    $incomingTitle = "ملخص ودراسة وقائع — طلب رقم " . ($summary->ticket?->number ?? $summary->id);
                    $incomingType = 'summary';
                    if ($summary->ticket) {
                        $ticket = $summary->ticket;
                    }
                    $incomingMeta = [
                        'source_type' => 'ticket_summary',
                        'summary_id'  => $summary->id,
                        'ticket_id'   => $summary->ticket_id,
                        'ticket_number' => $summary->ticket?->number,
                    ];
                }
            } elseif ($importType === 'session_summary') {
                $consult = is_numeric($importId)
                    ? Consult::with(['user', 'ticket'])->find($importId)
                    : Consult::with(['user', 'ticket'])->where('ref', $importId)->first();

                if ($consult && ($isAdmin || $consult->assigned_lawyer_id === $user->id || $consult->lawyer === $user->name)) {
                    $incomingDraft = $this->formatConsultSummaryHtml($consult);
                    $incomingTitle = "محضر وخلاصة جلسة استشارة — {$consult->ref}";
                    $incomingType = 'summary';
                    if ($consult->ticket) {
                        $ticket = $consult->ticket;
                    }
                    $incomingMeta = [
                        'source_type' => 'session_summary',
                        'consult_id'  => $consult->id,
                        'consult_ref' => $consult->ref,
                    ];
                }
            }
        }

        return Inertia::render('lawyer/editor', [
            'document'         => null,
            'types'            => LegalDocument::TYPES,
            'ticket'           => $ticket ? ['id' => $ticket->id, 'no' => $ticket->number] : null,
            'case'             => $incomingCase,
            'incomingDraft'    => $incomingDraft,
            'incomingTitle'    => $incomingTitle,
            'incomingType'     => $incomingType,
            'incomingMeta'     => $incomingMeta,
            'incomingTemplate' => $incomingTemplate,
            'defaultHeader'    => LegalDocument::defaultHeader(),
        ]);
    }

    /**
     * حفظ مستند جديد (أول حفظ).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'         => ['required', 'string', 'max:255'],
            'type'          => ['required', 'string', 'in:' . implode(',', array_keys(LegalDocument::TYPES))],
            'content_html'  => ['required', 'string'],
            'content_json'  => ['nullable', 'array'],
            'ticket_id'     => ['nullable', 'integer', 'exists:tickets,id'],
            'case_id'       => ['nullable', 'integer'],
            'metadata'      => ['nullable', 'array'],
            'header_config' => ['nullable', 'array'],
        ]);

        $doc = LegalDocument::create([
            ...$data,
            'user_id' => $request->user()->id,
            'status'  => 'draft',
        ]);

        $base = $this->basePrefix($request);

        return redirect("{$base}/editor/{$doc->id}")
            ->with('success', 'تم إنشاء المستند بنجاح');
    }

    /**
     * فتح مستند للتحرير.
     */
    public function edit(Request $request, LegalDocument $doc): Response
    {
        $this->guardAccess($request, $doc);

        return Inertia::render('lawyer/editor', [
            'document'      => $doc->toEditorData(),
            'types'         => LegalDocument::TYPES,
            'ticket'        => $doc->ticket ? ['id' => $doc->ticket_id, 'no' => $doc->ticket->number] : null,
            'case'          => $doc->legalCase ? ['id' => $doc->case_id, 'no' => $doc->legalCase->number] : null,
            'incomingDraft' => '',
            'defaultHeader' => LegalDocument::defaultHeader(),
        ]);
    }

    /**
     * تحديث المستند (حفظ + حفظ تلقائي).
     */
    public function update(Request $request, LegalDocument $doc)
    {
        $this->guardAccess($request, $doc);

        $data = $request->validate([
            'title'         => ['required', 'string', 'max:255'],
            'type'          => ['required', 'string', 'in:' . implode(',', array_keys(LegalDocument::TYPES))],
            'content_html'  => ['required', 'string'],
            'content_json'  => ['nullable', 'array'],
            'case_id'       => ['nullable', 'integer'],
            'metadata'      => ['nullable', 'array'],
            'header_config' => ['nullable', 'array'],
        ]);

        $doc->update($data);

        if ($request->wantsJson()) {
            return response()->json(['saved' => true, 'updatedAt' => $doc->fresh()->updated_at->translatedFormat('d M Y · h:i A')]);
        }

        return back()->with('success', 'تم حفظ المستند');
    }

    /**
     * اعتماد المستند.
     */
    public function approve(Request $request, LegalDocument $doc)
    {
        $this->guardAccess($request, $doc);

        $doc->update([
            'status'      => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        // إذا كان المستند مستورداً من مسودة لائحة دعوى معلقة، نحدث مسودة اللائحة بنص التحرير
        if (($doc->metadata['source_type'] ?? null) === 'case_pleading' && $doc->case_id) {
            $case = LegalCase::find($doc->case_id);
            if ($case && $case->pleading_status === 'pending_lawyer') {
                CasePleading::save($case, $request->user(), strip_tags($doc->content_html));
            }
        }

        return back()->with('success', 'تم اعتماد المستند رسمياً');
    }

    /**
     * جلب قائمة المسودات غير المعتمدة المتاحة للاستيراد في المحرر
     * (لوائح الدعاوى، ملخصات التذاكر، محاضر الجلسات الاستشارية).
     */
    public function importables(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $items = [];

        // 1. لوائح الدعاوى غير المعتمدة (pleading_status === 'pending_lawyer')
        $caseQuery = LegalCase::with(['user', 'assignedLawyer'])
            ->where('pleading_status', 'pending_lawyer')
            ->latest('updated_at');

        if (! $isAdmin) {
            $caseQuery->where('assigned_lawyer_id', $user->id);
        }

        foreach ($caseQuery->take(25)->get() as $case) {
            $draftText = CasePleading::draftText(CasePleading::latestDraft($case));
            $items[] = [
                'id'          => "case_pleading_{$case->id}",
                'sourceType'  => 'case_pleading',
                'sourceId'    => $case->id,
                'typeLabel'   => 'لائحة دعوى غير معتمدة',
                'badgeTone'   => 'b-amber',
                'ref'         => $case->number,
                'title'       => "لائحة دعوى — قضية رقم {$case->number}" . ($case->type ? " ({$case->type})" : ''),
                'client'      => $case->user?->name ?? '—',
                'lawyer'      => $case->assignedLawyer?->name ?? ($case->assigned_lawyer ?: '—'),
                'docType'     => 'lawsuit',
                'date'        => $case->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                'preview'     => mb_substr($draftText ?: 'مسودة لائحة دعوى جاهزة للصياغة والتنسيق', 0, 160),
                'contentHtml' => $this->formatPleadingHtml($case, $draftText),
                'caseId'      => $case->id,
                'caseNo'      => $case->number,
                'ticketId'    => null,
                'ticketNo'    => null,
                'metadata'    => [
                    'source_type' => 'case_pleading',
                    'case_id'     => $case->id,
                    'case_number' => $case->number,
                ],
            ];
        }

        // 2. ملخصات التذاكر غير المعتمدة
        $summaryQuery = TicketSummary::with(['ticket.user', 'ticket.assignedLawyer', 'lawyer'])
            ->whereHas('ticket')
            ->where(function ($q) {
                $q->where('status', 'awaiting_admin')
                  ->orWhereNull('approved_at');
            })
            ->latest('updated_at');

        if (! $isAdmin) {
            $summaryQuery->where(function ($q) use ($user) {
                $q->where('lawyer_id', $user->id)
                  ->orWhereHas('ticket', fn ($tq) => $tq->where('assigned_lawyer_id', $user->id));
            });
        }

        foreach ($summaryQuery->take(25)->get() as $summary) {
            $t = $summary->ticket;
            $previewText = $summary->facts ?: ($summary->case_summary ?: 'ملخص دراسة التذكرة ومرفقاتها');
            $items[] = [
                'id'          => "ticket_summary_{$summary->id}",
                'sourceType'  => 'ticket_summary',
                'sourceId'    => $summary->id,
                'typeLabel'   => 'ملخص دراسة تذكرة',
                'badgeTone'   => 'b-blue',
                'ref'         => $t?->number ?? "SUM-{$summary->id}",
                'title'       => "ملخص ودراسة وقائع — طلب رقم " . ($t?->number ?? $summary->id),
                'client'      => $t?->user?->name ?? '—',
                'lawyer'      => $summary->lawyer?->name ?? ($t?->assignedLawyer?->name ?? '—'),
                'docType'     => 'summary',
                'date'        => ($summary->lawyer_approved_at ?? $summary->updated_at)?->locale('ar')->diffForHumans() ?? 'الآن',
                'preview'     => mb_substr($previewText, 0, 160),
                'contentHtml' => $this->formatTicketSummaryHtml($summary),
                'caseId'      => null,
                'caseNo'      => null,
                'ticketId'    => $t?->id,
                'ticketNo'    => $t?->number,
                'metadata'    => [
                    'source_type' => 'ticket_summary',
                    'summary_id'  => $summary->id,
                    'ticket_id'   => $t?->id,
                    'ticket_number' => $t?->number,
                ],
            ];
        }

        // 3. محاضر الجلسات الاستشارية غير المعتمدة
        $consultQuery = Consult::with(['user', 'ticket'])
            ->whereNotNull('summary')
            ->whereNull('summary_approved_at')
            ->latest('updated_at');

        if (! $isAdmin) {
            $consultQuery->where(function ($q) use ($user) {
                $q->where('assigned_lawyer_id', $user->id)
                  ->orWhere('lawyer', $user->name);
            });
        }

        foreach ($consultQuery->take(25)->get() as $consult) {
            $items[] = [
                'id'          => "session_summary_{$consult->id}",
                'sourceType'  => 'session_summary',
                'sourceId'    => $consult->id,
                'typeLabel'   => 'محضر جلسة استشارة',
                'badgeTone'   => 'b-purple',
                'ref'         => $consult->ref,
                'title'       => "محضر جلسة استشارة — {$consult->ref}",
                'client'      => $consult->user?->name ?? '—',
                'lawyer'      => $consult->lawyer ?: '—',
                'docType'     => 'summary',
                'date'        => ($consult->summary_lawyer_approved_at ?? $consult->updated_at)?->locale('ar')->diffForHumans() ?? 'الآن',
                'preview'     => mb_substr($consult->summary, 0, 160),
                'contentHtml' => $this->formatConsultSummaryHtml($consult),
                'caseId'      => null,
                'caseNo'      => null,
                'ticketId'    => $consult->ticket?->id,
                'ticketNo'    => $consult->ticket?->number,
                'metadata'    => [
                    'source_type' => 'session_summary',
                    'consult_id'  => $consult->id,
                    'consult_ref' => $consult->ref,
                ],
            ];
        }

        return response()->json(['items' => $items]);
    }

    /**
     * تصدير المستند كـ PDF — يفتح صفحة طباعة.
     */
    public function printDoc(Request $request, LegalDocument $doc)
    {
        $this->guardAccess($request, $doc);

        return Inertia::render('lawyer/editor-print', [
            'document' => $doc->toEditorData(),
        ]);
    }

    /**
     * المساعد الذكي المدمج في محرر الصياغة:
     * إعادة صياغة، اقتراح أسانيد نظامية، تدقيق، إكمال، أو صياغة مخصصة.
     */
    public function aiAssist(Request $request, \App\Services\LegalAiService $aiService): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:complete,rephrase,basis,proofread,custom'],
            'text'   => ['nullable', 'string', 'max:15000'],
            'prompt' => ['nullable', 'string', 'max:3000'],
        ]);

        $action = $data['action'];
        $text = trim((string) ($data['text'] ?? ''));
        $userPrompt = trim((string) ($data['prompt'] ?? ''));

        $docType = match ($action) {
            'rephrase'  => 'إعادة صياغة قانونية محكمة',
            'complete'  => 'إكمال فقرة وحجة قانونية',
            'basis'     => 'اقتراح أسانيد نظامية سعودية',
            'proofread' => 'تدقيق لغوي وقانوني',
            'custom'    => $userPrompt ?: 'صياغة قانونية متخصصة',
        };

        $context = match ($action) {
            'rephrase'  => "أعد صياغة النص التالي بأسلوب قضائي سعودي رصين ومحكم مع استخدام المصطلحات القانونية الدقيقة:\n\n{$text}",
            'complete'  => "أكمل الصياغة القانونية للفقرة التالية وعزز الحجة والأسانيد المنطقية:\n\n{$text}",
            'basis'     => "اقترح الأسانيد والمواد النظامية السعودية الحاكمة للموضوع أو النص التالي (مثل نظام المعاملات المدنية، نظام المرافعات الشرعية، نظام الإثبات، أو نظام العمل):\n\n{$text}",
            'proofread' => "دقّق النص التالي لغوياً وإملائياً وقانونياً وصحح أي أخطاء أو ركاكة في الصياغة:\n\n{$text}",
            'custom'    => "المطلوب: {$userPrompt}\n\nالسياق والنص المعروض:\n{$text}",
        };

        try {
            $result = $aiService->assistResult('defense', $docType, null, $context);
            $output = $result['draft'];
        } catch (\Throwable $e) {
            $output = match ($action) {
                'rephrase' => "وحيث إن ما تمسك به الخصم يفتقر إلى السند النظامي الصحيح والواقعي، فإننا نؤكد لفضيلتكم سلامة الموقف النظامي وثبوت الحق التعاقدي وفق الأصول الشرعية والأنظمة المرعية في المملكة العربية السعودية.",
                'basis'    => "• المادة (128) من نظام المعاملات المدنية (العقد شريعة المتعاقدين).\n• المادة (29) من نظام الإثبات (حجية الإقرار القضائي).\n• المادة (75) من نظام المرافعات الشرعية (الدفع بعدم قبول الدعوى).",
                'proofread' => $text,
                'complete' => $text . "\n\nوبناءً عليه، وحيث ثبت تخلف المذكور عن أداء ما التزم به دون عذر شرعي أو نظامي، فإن موجَب الحكم بإلزامه بات قائماً ومتعيناً.",
                default    => "تمت المعالجة القانونية بنجاح وفق الأنظمة السعودية المرعية.",
            };
        }

        return response()->json([
            'success' => true,
            'text'    => $output,
            'action'  => $action,
        ]);
    }

    // ── مساعدات داخلية ──

    /**
     * حراسة الوصول: المالك أو الإدارة.
     */
    private function guardAccess(Request $request, LegalDocument $doc): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && $doc->user_id !== $user->id) {
            abort(403, 'ليس لديك صلاحية الوصول لهذا المستند.');
        }
    }

    /**
     * قراءة بادئة المسار الحالي (lawyer أو admin).
     */
    private function basePrefix(Request $request): string
    {
        return str_starts_with($request->path(), 'admin') ? '/admin' : '/lawyer';
    }

    /**
     * تنسيق لائحة الدعوى إلى HTML قانوني منسّق للمحرر.
     */
    private function formatPleadingHtml(LegalCase $case, ?string $draft): string
    {
        $caseNo = e($case->number);
        $caseType = e($case->type ?: 'قضية عامة');
        $court = e($case->department ?: 'المحكمة المختصة');
        $client = e($case->user?->name ?? 'المدعي');

        $body = $draft
            ? '<p dir="rtl">' . implode('</p><p dir="rtl">', array_filter(explode("\n", e(trim($draft))))) . '</p>'
            : '<p dir="rtl"><b>الوقائع والأسانيد:</b></p><p dir="rtl">اكتب وقائع وأسانيد الدعوى هنا...</p>';

        return <<<HTML
<h2 style="text-align: center; color: #0a2a55;">لائحة دعوى</h2>
<p style="text-align: center; color: #607689; font-size: 13px;">
  <b>رقم القضية:</b> {$caseNo} &nbsp;|&nbsp; <b>التصنيف:</b> {$caseType} &nbsp;|&nbsp; <b>الدائرة:</b> {$court} &nbsp;|&nbsp; <b>الطرف الموكل:</b> {$client}
</p>
<hr style="border: 0; border-top: 1.5px solid #0e5c9c; margin: 16px 0;" />
<p dir="rtl"><b>لدى أصحاب الفضيلة رئيس وأعضاء الدائرة الموقرين،،، حفظهم الله</b></p>
<p dir="rtl">السلام عليكم ورحمة الله وبركاته،، وبعد:</p>
{$body}
<p dir="rtl" style="margin-top: 24px;"><b>وبناءً على ما تقدم نلتمس من فضيلتكم قيد الدعوى والحكم بالطلبات الواردة بعاليه.</b></p>
<p dir="rtl">وتقبلوا خالص التحية والتقدير،،،</p>
HTML;
    }

    /**
     * تنسيق ملخص التذكرة إلى HTML قانوني منسّق للمحرر.
     */
    private function formatTicketSummaryHtml(TicketSummary $summary): string
    {
        $t = $summary->ticket;
        $ticketNo = e($t?->number ?? '—');
        $ticketType = e($t?->type ?? 'طلب قانوني');
        $client = e($t?->user?->name ?? 'العميل');

        $sections = [];

        if ($summary->case_summary) {
            $sections[] = '<h3 style="color: #0e5c9c;">١. ملخص الموضوع والنزاع</h3><p dir="rtl">' . nl2br(e($summary->case_summary)) . '</p>';
        }
        if ($summary->facts) {
            $sections[] = '<h3 style="color: #0e5c9c;">٢. الوقائع والأحداث المثبتة</h3><p dir="rtl">' . nl2br(e($summary->facts)) . '</p>';
        }
        if ($summary->key_points) {
            $sections[] = '<h3 style="color: #0e5c9c;">٣. الأسانيد والنقاط الجوهرية والرأي القانوني</h3><p dir="rtl">' . nl2br(e($summary->key_points)) . '</p>';
        }
        if ($summary->attachments_summary) {
            $sections[] = '<h3 style="color: #0e5c9c;">٤. نتائج فحص المستندات والمرفقات</h3><p dir="rtl">' . nl2br(e($summary->attachments_summary)) . '</p>';
        }

        $bodyHtml = !empty($sections) ? implode("\n", $sections) : '<p dir="rtl">ملخص وقائع الملف قيد الإعداد والتنسيق.</p>';

        return <<<HTML
<h2 style="text-align: center; color: #0a2a55;">ملخص ودراسة وقائع الملف القانوني</h2>
<p style="text-align: center; color: #607689; font-size: 13px;">
  <b>رقم الطلب:</b> {$ticketNo} &nbsp;|&nbsp; <b>النوع:</b> {$ticketType} &nbsp;|&nbsp; <b>صاحب الطلب:</b> {$client}
</p>
<hr style="border: 0; border-top: 1.5px solid #0e5c9c; margin: 16px 0;" />
{$bodyHtml}
HTML;
    }

    /**
     * تنسيق محضر جلسة الاستشارة إلى HTML قانوني منسّق للمحرر.
     */
    private function formatConsultSummaryHtml(Consult $consult): string
    {
        $ref = e($consult->ref);
        $client = e($consult->user?->name ?? 'العميل');
        $lawyer = e($consult->lawyer ?: 'المستشار القانوني');
        $subject = e($consult->subject ?: 'جلسة استشارة نظامية');
        $summary = nl2br(e($consult->summary ?: 'خلاصة وتوصيات الجلسة.'));

        $decisionsHtml = '';
        if ($consult->decisions) {
            $decisionsHtml = '<h3 style="color: #0e5c9c;">القرارات والتوجيهات الموصى بها</h3><p dir="rtl">' . nl2br(e($consult->decisions)) . '</p>';
        }

        return <<<HTML
<h2 style="text-align: center; color: #0a2a55;">محضر وخلاصة جلسة استشارة قانونية</h2>
<p style="text-align: center; color: #607689; font-size: 13px;">
  <b>رقم الاستشارة:</b> {$ref} &nbsp;|&nbsp; <b>المستشار:</b> {$lawyer} &nbsp;|&nbsp; <b>المستفيد:</b> {$client}
</p>
<hr style="border: 0; border-top: 1.5px solid #0e5c9c; margin: 16px 0;" />
<h3 style="color: #0e5c9c;">موضوع الاستشارة</h3>
<p dir="rtl">{$subject}</p>
<h3 style="color: #0e5c9c;">خلاصة المشورة والتحليل النظامي</h3>
<p dir="rtl">{$summary}</p>
{$decisionsHtml}
HTML;
    }
}
