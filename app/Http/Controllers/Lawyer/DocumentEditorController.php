<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\LegalDocument;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\CasePleading;
use App\Support\PdfRenderer;
use App\Support\Permissions;
use App\Support\SettingsRegistry;
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
     * مفتاح الجلسة الذي يسلّم به المساعد القانوني مسودّته للمحرّر (`AssistantController::toEditor`).
     * رسالةٌ لمرّةٍ واحدة (`flash`) لا عنوان صفحة: المسودّة تصل صاحبها وحده ولا تُعاد بإعادة التحميل.
     */
    public const ASSISTANT_DRAFT = 'assistant_draft';

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
            'types' => LegalDocument::TYPES,
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

        // **مسودّة المساعد من الجلسة لا من العنوان.** كان `?draft=` يُقرأ ويُدخله المحرّر كما هو إن
        // بدأ بـ`<` — فرابطٌ مصنوع يحقن وسوماً في محرّر محامٍ. الآن لا مصدر للمسودّة الحرّة إلّا ما
        // سلّمه `AssistantController::toEditor` لهذه الجلسة، ويُهرَّب نصّاً عاديّاً إلى فقرات.
        $handoff = $request->session()->get(self::ASSISTANT_DRAFT);
        $incomingDraft = is_array($handoff) ? self::plainTextToHtml((string) ($handoff['text'] ?? '')) : '';
        $incomingTemplate = (string) $request->query('template', '');
        $incomingTitle = is_array($handoff) ? (string) ($handoff['title'] ?? '') : '';
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
                    $incomingTitle = "لائحة دعوى — قضية رقم {$case->number}".($case->type ? " ({$case->type})" : '');
                    $incomingType = 'lawsuit';
                    $incomingCase = ['id' => $case->id, 'no' => $case->number];
                    $incomingMeta = [
                        'source_type' => 'case_pleading',
                        'case_id' => $case->id,
                        'case_number' => $case->number,
                    ];
                }
            } elseif ($importType === 'ticket_summary') {
                $summary = TicketSummary::with(['ticket.user', 'ticket.assignedLawyer', 'lawyer'])->find($importId);
                if ($summary && ($isAdmin || $summary->lawyer_id === $user->id || $summary->ticket?->assigned_lawyer_id === $user->id)) {
                    $incomingDraft = $this->formatTicketSummaryHtml($summary);
                    $incomingTitle = 'ملخص ودراسة وقائع — طلب رقم '.($summary->ticket?->number ?? $summary->id);
                    $incomingType = 'summary';
                    if ($summary->ticket) {
                        $ticket = $summary->ticket;
                    }
                    $incomingMeta = [
                        'source_type' => 'ticket_summary',
                        'summary_id' => $summary->id,
                        'ticket_id' => $summary->ticket_id,
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
                        'consult_id' => $consult->id,
                        'consult_ref' => $consult->ref,
                    ];
                }
            }
        }

        return Inertia::render('lawyer/editor', [
            'document' => null,
            'types' => LegalDocument::TYPES,
            'ticket' => $ticket ? ['id' => $ticket->id, 'no' => $ticket->number] : null,
            'case' => $incomingCase,
            'incomingDraft' => $incomingDraft,
            'incomingTitle' => $incomingTitle,
            'incomingType' => $incomingType,
            'incomingMeta' => $incomingMeta,
            'incomingTemplate' => $incomingTemplate,
            'defaultHeader' => LegalDocument::defaultHeader(),
            'canApprove' => $user->isAdmin() || $user->can(Permissions::APPROVE_DOCUMENTS),
        ]);
    }

    /**
     * حفظ مستند جديد (أول حفظ).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(LegalDocument::TYPES))],
            'content_html' => ['required', 'string'],
            'content_json' => ['nullable', 'array'],
            'ticket_id' => ['nullable', 'integer', 'exists:tickets,id'],
            'case_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
            'header_config' => ['nullable', 'array'],
        ]);
        $data = $this->guardCaseLink($request, $data);

        $doc = LegalDocument::create([
            ...$data,
            'user_id' => $request->user()->id,
            'status' => 'draft',
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
        $user = $request->user();

        return Inertia::render('lawyer/editor', [
            'document' => $doc->toEditorData(),
            'types' => LegalDocument::TYPES,
            'ticket' => $doc->ticket ? ['id' => $doc->ticket_id, 'no' => $doc->ticket->number] : null,
            'case' => $doc->legalCase ? ['id' => $doc->case_id, 'no' => $doc->legalCase->number] : null,
            'incomingDraft' => '',
            'defaultHeader' => LegalDocument::defaultHeader(),
            'canApprove' => $user->isAdmin() || $user->can(Permissions::APPROVE_DOCUMENTS),
        ]);
    }

    /**
     * تحديث المستند (حفظ + حفظ تلقائي).
     */
    public function update(Request $request, LegalDocument $doc)
    {
        $this->guardAccess($request, $doc);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(LegalDocument::TYPES))],
            'content_html' => ['required', 'string'],
            'content_json' => ['nullable', 'array'],
            'case_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
            'header_config' => ['nullable', 'array'],
        ]);
        $data = $this->guardCaseLink($request, $data, $doc);

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

        $user = $request->user();
        if (! $user->isAdmin() && ! $user->can(Permissions::APPROVE_DOCUMENTS)) {
            abort(403, 'ليس لديك صلاحية اعتماد الصياغة القانونية.');
        }
        // المستند المربوط بقضية يكتب لائحتها عند الاعتماد — فلا يعتمده إلّا من يملك القضية
        if ($doc->case_id && ! self::canUseCase($user, LegalCase::find($doc->case_id))) {
            abort(403, 'هذه القضية غير مُسندة إليك.');
        }

        $doc->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        // إذا كان المستند مستورداً من مسودة لائحة دعوى معلقة، نحدث مسودة اللائحة بنص منظم يحافظ على الفقرات
        if (($doc->metadata['source_type'] ?? null) === 'case_pleading' && $doc->case_id) {
            $case = LegalCase::find($doc->case_id);
            if ($case && $case->pleading_status === 'pending_lawyer') {
                CasePleading::save($case, $request->user(), self::htmlToPlainText($doc->content_html));
            }
        }

        return back()->with('success', 'تم اعتماد المستند رسمياً');
    }

    /**
     * تحويل محتوى HTML إلى نص قضائي منظم يحافظ على فواصل الأسطر والفقرات
     * بدلاً من `strip_tags` البحت الذي يدمج الفقرات والكلمات ببعضها.
     */
    public static function htmlToPlainText(string $html): string
    {
        // 1. استبدال فواصل الأسطر الصريحة
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // 2. تحويل نهايات وسوم الكتل (الفقرات والعناوين والصفوف) إلى أسطر جديدة
        $text = preg_replace('/<\/(p|div|h[1-6]|tr|blockquote|li)>/i', "\n\n", (string) $text);

        // 3. تحويل عناصر القوائم إلى علامات نقطية
        $text = preg_replace('/<li[^>]*>/i', '• ', (string) $text);

        // 4. فك تشفير الكيانات وتجريد بقية وسوم HTML
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);

        // 5. ضبط الفراغات وتوحيد الأسطر الزائدة (أقصى فراغ سطران فارغان)
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', (string) $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);

        return trim((string) $text);
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
                'id' => "case_pleading_{$case->id}",
                'sourceType' => 'case_pleading',
                'sourceId' => $case->id,
                'typeLabel' => 'لائحة دعوى غير معتمدة',
                'badgeTone' => 'b-amber',
                'ref' => $case->number,
                'title' => "لائحة دعوى — قضية رقم {$case->number}".($case->type ? " ({$case->type})" : ''),
                'client' => $case->user?->name ?? '—',
                'lawyer' => $case->assignedLawyer?->name ?? ($case->assigned_lawyer ?: '—'),
                'docType' => 'lawsuit',
                'date' => $case->updated_at?->locale('ar')->diffForHumans() ?? 'الآن',
                'preview' => mb_substr($draftText ?: 'مسودة لائحة دعوى جاهزة للصياغة والتنسيق', 0, 160),
                'contentHtml' => $this->formatPleadingHtml($case, $draftText),
                'caseId' => $case->id,
                'caseNo' => $case->number,
                'ticketId' => null,
                'ticketNo' => null,
                'metadata' => [
                    'source_type' => 'case_pleading',
                    'case_id' => $case->id,
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
                'id' => "ticket_summary_{$summary->id}",
                'sourceType' => 'ticket_summary',
                'sourceId' => $summary->id,
                'typeLabel' => 'ملخص دراسة تذكرة',
                'badgeTone' => 'b-blue',
                'ref' => $t?->number ?? "SUM-{$summary->id}",
                'title' => 'ملخص ودراسة وقائع — طلب رقم '.($t?->number ?? $summary->id),
                'client' => $t?->user?->name ?? '—',
                'lawyer' => $summary->lawyer?->name ?? ($t?->assignedLawyer?->name ?? '—'),
                'docType' => 'summary',
                'date' => ($summary->lawyer_approved_at ?? $summary->updated_at)?->locale('ar')->diffForHumans() ?? 'الآن',
                'preview' => mb_substr($previewText, 0, 160),
                'contentHtml' => $this->formatTicketSummaryHtml($summary),
                'caseId' => null,
                'caseNo' => null,
                'ticketId' => $t?->id,
                'ticketNo' => $t?->number,
                'metadata' => [
                    'source_type' => 'ticket_summary',
                    'summary_id' => $summary->id,
                    'ticket_id' => $t?->id,
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
                'id' => "session_summary_{$consult->id}",
                'sourceType' => 'session_summary',
                'sourceId' => $consult->id,
                'typeLabel' => 'محضر جلسة استشارة',
                'badgeTone' => 'b-purple',
                'ref' => $consult->ref,
                'title' => "محضر جلسة استشارة — {$consult->ref}",
                'client' => $consult->user?->name ?? '—',
                'lawyer' => $consult->lawyer ?: '—',
                'docType' => 'summary',
                'date' => ($consult->summary_lawyer_approved_at ?? $consult->updated_at)?->locale('ar')->diffForHumans() ?? 'الآن',
                'preview' => mb_substr($consult->summary, 0, 160),
                'contentHtml' => $this->formatConsultSummaryHtml($consult),
                'caseId' => null,
                'caseNo' => null,
                'ticketId' => $consult->ticket?->id,
                'ticketNo' => $consult->ticket?->number,
                'metadata' => [
                    'source_type' => 'session_summary',
                    'consult_id' => $consult->id,
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
     * تحميل المستند القانوني كملف PDF حقيقي عبر Browsershot / Puppeteer.
     */
    public function downloadPdf(Request $request, LegalDocument $doc)
    {
        $this->guardAccess($request, $doc);

        $html = $this->buildDocumentPdfHtml($doc);
        $safeTitle = preg_replace('/[^\p{Arabic}\p{L}\p{N}\-_ ]/u', '', $doc->title) ?: 'document';
        $filename = "{$safeTitle}.pdf";

        return PdfRenderer::render($html, $filename, 'A4');
    }

    /**
     * بناء HTML متكامل للمستند القانوني لتصييره إلى PDF حقيقي عبر Browsershot.
     */
    public function buildDocumentPdfHtml(LegalDocument $doc): string
    {
        $header = $doc->header_config ?? LegalDocument::defaultHeader();
        $showHeader = ! empty($header['showHeader']);
        $officeName = e(($header['officeName'] ?? '') ?: SettingsRegistry::str('office_name'));
        $officeNameEn = e($header['officeNameEn'] ?? '');
        $licenseNo = e($header['licenseNo'] ?? '');
        $phone = e($header['phone'] ?? '');
        $email = e($header['email'] ?? '');
        $address = e($header['address'] ?? '');

        // معالجة الشعار كـ Data URI لضمان ظهوره في PDF بلا حاجة لطلب شبكة
        $logoDataUri = null;
        if (! empty($header['logoUrl'])) {
            $logoUrl = $header['logoUrl'];
            if (str_starts_with($logoUrl, 'data:image')) {
                $logoDataUri = $logoUrl;
            } elseif (($localPath = self::publicImagePath((string) $logoUrl)) !== null) {
                $mime = str_ends_with($localPath, '.svg') ? 'image/svg+xml' : (str_ends_with($localPath, '.png') ? 'image/png' : 'image/jpeg');
                $logoDataUri = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($localPath));
            }
        }
        if (! $logoDataUri) {
            $defaultLogo = public_path('images/021.png');
            if (is_file($defaultLogo)) {
                $logoDataUri = 'data:image/png;base64,'.base64_encode((string) file_get_contents($defaultLogo));
            }
        }

        $headerHtml = '';
        if ($showHeader) {
            $headerHtml = <<<HTML
            <div class="legal-header" style="margin-bottom: 20px; border-bottom: 2.5px solid #0e5c9c; padding-bottom: 12px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="width: 25%; text-align: right; vertical-align: middle; border: none;">
                            <img src="{$logoDataUri}" alt="شعار" style="max-height: 52px; max-width: 140px; object-fit: contain;" />
                        </td>
                        <td style="width: 50%; text-align: center; vertical-align: middle; border: none;">
                            <div style="font-size: 17px; font-weight: 800; color: #0a2a55;">{$officeName}</div>
                            <div style="font-size: 11px; color: #607689; font-family: sans-serif; margin-top: 2px;">{$officeNameEn}</div>
                            <div style="font-size: 10.5px; color: #607689; margin-top: 2px;">ترخيص رقم: {$licenseNo}</div>
                        </td>
                        <td style="width: 25%; text-align: left; vertical-align: middle; border: none; font-size: 10px; color: #607689; line-height: 1.6;">
                            <div>{$phone}</div>
                            <div>{$email}</div>
                            <div>{$address}</div>
                        </td>
                    </tr>
                </table>
            </div>
HTML;
        }

        $refNo = 'DOC-'.str_pad((string) $doc->id, 5, '0', STR_PAD_LEFT);
        $typeLabel = e(LegalDocument::TYPES[$doc->type] ?? $doc->type);
        $caseNoHtml = $doc->legalCase ? '<div>القضية: <strong style="color: #0e5c9c;">'.e($doc->legalCase->number).'</strong></div>' : '';
        $ticketNoHtml = $doc->ticket ? '<div>التذكرة: <strong style="color: #13314f;">'.e($doc->ticket->number).'</strong></div>' : '';
        $dateStr = $doc->created_at ? $doc->created_at->translatedFormat('d M Y') : date('Y-m-d');
        $title = e($doc->title);
        $author = e($doc->user?->name ?? 'المحامي المختص');
        $approvedBadge = '';
        if ($doc->status === 'approved') {
            $approver = e($doc->approver?->name ?? 'الإدارة');
            $approvedAt = $doc->approved_at ? $doc->approved_at->translatedFormat('d M Y') : '';
            $approvedBadge = <<<HTML
            <div style="border: 2px solid #1e9d6b; border-radius: 8px; padding: 6px 14px; text-align: center; background: #e7f6ef; color: #1e9d6b;">
                <div style="font-size: 12px; font-weight: 800;">✓ معتمد رسمياً من الإدارة</div>
                <div style="font-size: 10.5px;">المعتمد: {$approver}</div>
                <div style="font-size: 9.5px;">بتاريخ: {$approvedAt}</div>
            </div>
HTML;
        }

        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <title>{$title}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Amiri:ital,wght@0,400;0,700;1,400;1,700&family=Cairo:wght@400;600;700;800&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        @page {
            size: A4 portrait;
            margin: 14mm 14mm 18mm 14mm;
        }
        body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            font-family: 'Tajawal', 'Traditional Arabic', Arial, sans-serif;
            font-size: 13.5pt;
            line-height: 1.85;
            color: #13314f;
            direction: rtl;
            text-align: right;
        }
        .container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }
        .ref-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #edf2f6;
            border-radius: 6px;
            padding: 6px 14px;
            margin-bottom: 22px;
            font-size: 11px;
            color: #607689;
        }
        h1.doc-title {
            text-align: center;
            font-size: 21pt;
            font-weight: 800;
            color: #0a2a55;
            margin: 0 0 24px;
            padding-bottom: 8px;
            border-bottom: 1.5px solid #edf2f6;
        }
        .content {
            min-height: 500px;
        }
        .content p { margin-bottom: 0.85em; }
        .content h1, .content h2, .content h3, .content h4 {
            color: #0a2a55;
            page-break-after: avoid;
            break-after: avoid;
        }
        .content h1 { font-size: 20pt; }
        .content h2 { font-size: 17pt; color: #0e5c9c; margin-top: 1em; }
        .content h3 { font-size: 15pt; color: #13314f; margin-top: 0.85em; }
        .content h4 { font-size: 13.5pt; margin-top: 0.7em; }
        .content table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.2em 0;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .content th, .content td {
            border: 1px solid #ccd7e0;
            padding: 8px 12px;
            text-align: right;
            vertical-align: top;
        }
        .content th {
            background: #f1f5f8;
            font-weight: bold;
            color: #0a2a55;
        }
        .content blockquote {
            border-right: 4px solid #0e5c9c;
            padding: 8px 14px;
            background: #f8fafc;
            margin: 1em 0;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .content img {
            max-width: 100%;
            height: auto;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .doc-footer-block {
            margin-top: 40px;
            padding-top: 18px;
            border-top: 1px solid #e1e8ee;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .footer-closing {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
        }
        .copyright-line {
            text-align: center;
            font-size: 9.5pt;
            color: #90a2b2;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #edf2f6;
        }
    </style>
</head>
<body>
<div class="container">
    {$headerHtml}

    <div class="ref-bar">
        <div>الرقم المرجعي: <strong style="color: #13314f;">{$refNo}</strong></div>
        <div>التصنيف: <strong style="color: #0e5c9c;">{$typeLabel}</strong></div>
        {$caseNoHtml}
        {$ticketNoHtml}
        <div>التاريخ: <strong style="color: #13314f;">{$dateStr}</strong></div>
    </div>

    <h1 class="doc-title">{$title}</h1>

    <div class="content">
        {$doc->content_html}
    </div>

    <div class="doc-footer-block">
        <div class="footer-closing">
            <div>
                <div style="font-size: 11px; color: #607689;">حرر بواسطة:</div>
                <div style="font-size: 13px; font-weight: 700; color: #13314f; margin-top: 3px;">{$author}</div>
            </div>
            {$approvedBadge}
            <div style="text-align: left;">
                <div style="font-size: 11px; color: #607689;">التوقيع والختم</div>
                <div style="height: 38px; width: 120px; border-bottom: 1px dashed #90a2b2; margin-top: 6px;"></div>
            </div>
        </div>
        <div class="copyright-line">
            هذا المستند صادر من المنصة القانونية — سري ومحمي بموجب الأنظمة المرعية © {$year}
        </div>
    </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * المساعد الذكي المدمج في محرر الصياغة:
     * إعادة صياغة، اقتراح أسانيد نظامية، تدقيق، إكمال، أو صياغة مخصصة.
     */
    public function aiAssist(Request $request, LegalAiService $aiService): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:complete,rephrase,basis,proofread,custom'],
            'text' => ['nullable', 'string', 'max:15000'],
            'prompt' => ['nullable', 'string', 'max:3000'],
        ]);

        $action = $data['action'];
        $text = trim((string) ($data['text'] ?? ''));
        $userPrompt = trim((string) ($data['prompt'] ?? ''));

        $docType = match ($action) {
            'rephrase' => 'إعادة صياغة قانونية محكمة',
            'complete' => 'إكمال فقرة وحجة قانونية',
            'basis' => 'اقتراح أسانيد نظامية سعودية',
            'proofread' => 'تدقيق لغوي وقانوني',
            'custom' => $userPrompt ?: 'صياغة قانونية متخصصة',
        };

        $context = match ($action) {
            'rephrase' => "أعد صياغة النص التالي بأسلوب قضائي سعودي رصين ومحكم مع استخدام المصطلحات القانونية الدقيقة:\n\n{$text}",
            'complete' => "أكمل الصياغة القانونية للفقرة التالية وعزز الحجة والأسانيد المنطقية:\n\n{$text}",
            'basis' => "اقترح الأسانيد والمواد النظامية السعودية الحاكمة للموضوع أو النص التالي (مثل نظام المعاملات المدنية، نظام المرافعات الشرعية، نظام الإثبات، أو نظام العمل):\n\n{$text}",
            'proofread' => "دقّق النص التالي لغوياً وإملائياً وقانونياً وصحح أي أخطاء أو ركاكة في الصياغة:\n\n{$text}",
            'custom' => "المطلوب: {$userPrompt}\n\nالسياق والنص المعروض:\n{$text}",
        };

        try {
            $result = $aiService->assistResult('defense', $docType, null, $context);
            $output = $result['draft'];
        } catch (\Throwable $e) {
            $output = match ($action) {
                'rephrase' => 'وحيث إن ما تمسك به الخصم يفتقر إلى السند النظامي الصحيح والواقعي، فإننا نؤكد لفضيلتكم سلامة الموقف النظامي وثبوت الحق التعاقدي وفق الأصول الشرعية والأنظمة المرعية في المملكة العربية السعودية.',
                'basis' => "• المادة (128) من نظام المعاملات المدنية (العقد شريعة المتعاقدين).\n• المادة (29) من نظام الإثبات (حجية الإقرار القضائي).\n• المادة (75) من نظام المرافعات الشرعية (الدفع بعدم قبول الدعوى).",
                'proofread' => $text,
                'complete' => $text."\n\nوبناءً عليه، وحيث ثبت تخلف المذكور عن أداء ما التزم به دون عذر شرعي أو نظامي، فإن موجَب الحكم بإلزامه بات قائماً ومتعيناً.",
                default => 'تمت المعالجة القانونية بنجاح وفق الأنظمة السعودية المرعية.',
            };
        }

        return response()->json([
            'success' => true,
            'text' => $output,
            'action' => $action,
        ]);
    }

    // ── مساعدات داخلية ──

    /** القضية تُربط بمستندٍ لمن يملكها: الإدارة أو المحامي المُسندة إليه — القاعدة نفسها في `create`/`importables`. */
    private static function canUseCase(User $user, ?LegalCase $case): bool
    {
        return $case !== null && ($user->isAdmin() || (int) $case->assigned_lawyer_id === (int) $user->id);
    }

    /**
     * ربط المستند بقضية حكمُ الخادم لا الطلب.
     *
     * كان `case_id` و`metadata` يُقبلان كما أُرسلا، ثم يكتب `approve()` لائحة تلك القضية عبر
     * `CasePleading::save` — فمن يملك صلاحية الاعتماد يستبدل لائحة قضيةٍ ليست له. الآن: القضية
     * لمن يملكها وإلّا 403، و`source_type=case_pleading` لا يبقى إلّا مطابقاً للقضية المربوطة.
     * الحفظ على الربط القائم نفسه لا يُعاد فحصه (أُعيد إسناد القضية؟ يبقى المسودّة حفظها،
     * والاعتماد وحده يُحرس في `approve`).
     */
    private function guardCaseLink(Request $request, array $data, ?LegalDocument $doc = null): array
    {
        if (! empty($data['case_id']) && (int) $data['case_id'] !== (int) $doc?->case_id) {
            abort_unless(self::canUseCase($request->user(), LegalCase::find($data['case_id'])), 403, 'هذه القضية غير مُسندة إليك.');
        }

        if (is_array($data['metadata'] ?? null) && ($data['metadata']['source_type'] ?? null) === 'case_pleading'
            && (empty($data['case_id']) || (int) ($data['metadata']['case_id'] ?? 0) !== (int) $data['case_id'])) {
            unset($data['metadata']['source_type']);
        }

        return $data;
    }

    /**
     * مسار صورة الشعار على القرص — **داخل `public/` حصراً وبامتداد صورة**، أو `null`.
     *
     * `logoUrl` يكتبه صاحب المستند في ترويسته؛ وكان يُمرَّر إلى `public_path()` كما هو، فـ`/../.env`
     * يقرأ ملف البيئة ويضمّنه في الـPDF (مفتاح التطبيق وكلمات المرور). `realpath` يحلّ `..` والروابط
     * الرمزيّة، ثم يُشترط أن يبقى الناتج تحت `public/`.
     */
    public static function publicImagePath(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (! preg_match('/\.(png|jpe?g|svg)$/i', $path)) {
            return null;
        }

        $root = realpath(public_path());
        $real = realpath(public_path(ltrim($path, '/')));

        return ($root !== false && $real !== false && is_file($real) && str_starts_with($real, $root.DIRECTORY_SEPARATOR))
            ? $real
            : null;
    }

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
     * قراءة بادئة المسار الحالي (lawyer أو admin أو employee).
     */
    private function basePrefix(Request $request): string
    {
        if (str_starts_with($request->path(), 'admin')) {
            return '/admin';
        }
        if (str_starts_with($request->path(), 'employee')) {
            return '/employee';
        }

        return '/lawyer';
    }

    /**
     * نصٌّ عاديّ ⇦ فقرات HTML **مهرَّبة**: السطر الفارغ يفصل الفقرات، والسطر المفرد فاصل سطر.
     * التهريب هنا لا في الواجهة — المحرّر يُدخل ما يصله HTML، فالنصّ الخام لا يبلغه بحال.
     */
    public static function plainTextToHtml(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];

        return implode('', array_map(
            fn (string $p) => '<p dir="rtl">'.nl2br(e(trim($p)), false).'</p>',
            $paragraphs,
        ));
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
            ? '<p dir="rtl">'.implode('</p><p dir="rtl">', array_filter(explode("\n", e(trim($draft))))).'</p>'
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
            $sections[] = '<h3 style="color: #0e5c9c;">١. ملخص الموضوع والنزاع</h3><p dir="rtl">'.nl2br(e($summary->case_summary)).'</p>';
        }
        if ($summary->facts) {
            $sections[] = '<h3 style="color: #0e5c9c;">٢. الوقائع والأحداث المثبتة</h3><p dir="rtl">'.nl2br(e($summary->facts)).'</p>';
        }
        if ($summary->key_points) {
            $sections[] = '<h3 style="color: #0e5c9c;">٣. الأسانيد والنقاط الجوهرية والرأي القانوني</h3><p dir="rtl">'.nl2br(e($summary->key_points)).'</p>';
        }
        if ($summary->attachments_summary) {
            $sections[] = '<h3 style="color: #0e5c9c;">٤. نتائج فحص المستندات والمرفقات</h3><p dir="rtl">'.nl2br(e($summary->attachments_summary)).'</p>';
        }

        $bodyHtml = ! empty($sections) ? implode("\n", $sections) : '<p dir="rtl">ملخص وقائع الملف قيد الإعداد والتنسيق.</p>';

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
        // المحضر يُطبع ويُرسل للعميل — فالاسم كما يراه العميل (`LawyerName::forClient` عبر الإعداد)، لا الخام
        $lawyer = e($consult->lawyerForClient('المستشار القانوني'));
        $subject = e($consult->subject ?: 'جلسة استشارة نظامية');
        $summary = nl2br(e($consult->summary ?: 'خلاصة وتوصيات الجلسة.'));

        $decisionsHtml = '';
        if ($consult->decisions) {
            $decisionsHtml = '<h3 style="color: #0e5c9c;">القرارات والتوجيهات الموصى بها</h3><p dir="rtl">'.nl2br(e($consult->decisions)).'</p>';
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
