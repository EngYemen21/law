<?php

namespace App\Http\Controllers;

use App\Models\CaseDocument;
use App\Models\Document;
use App\Models\ExecutionDocument;
use App\Models\TicketDocument;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    use AuthorizesRequests;

    /** امتدادات المستندات المسموح رفعها من العميل — تطابق TicketController (لا تنفيذية/مضغوطة). */
    private const ALLOWED_DOC_MIMES = 'pdf,jpg,jpeg,png,doc,docx';

    // مستندات العميل الحالي: تجميع كافة المستندات والملفات الصادرة له من المكتب والقضايا والتنفيذ والتذاكر
    public function index(Request $request): Response
    {
        $user = $request->user();

        // 1. المستندات الصادرة المباشرة من جدول documents
        $directOutDocs = Document::where('user_id', $user->id)
            ->where('direction', 'out')
            ->latest('id')->get()
            ->map(fn (Document $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'meta' => $d->meta,
                'canDownload' => $d->path !== null,
                'downloadUrl' => $d->path ? route('documents.download', $d->id) : null,
                'at' => $d->created_at?->getTimestamp() ?? 0,
            ]);

        // 2. مستندات القضايا: ما أرفقه/أصدره المكتب، وما رفعه العميل بنفسه
        $caseDocs = CaseDocument::whereHas('legalCase', fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')->get()
            ->map(fn (CaseDocument $cd) => [
                'id' => 'case-'.$cd->id,
                'name' => $cd->name,
                // المصدر من `uploaded_by` الذي يكتبه رافعُه — لا وسمَ «معتمد من المكتب» على مستند العميل
                'meta' => 'مستند قضية · '.($cd->doc_type ?: ($cd->uploaded_by === 'client' ? 'مرفوع منك' : 'معتمد من المكتب')),
                'canDownload' => ! empty($cd->path),
                'downloadUrl' => ! empty($cd->path) ? route('documents.download-file', ['type' => 'case', 'id' => $cd->id]) : null,
                'at' => $cd->created_at?->getTimestamp() ?? 0,
            ]);

        // 3. مستندات وسندات التنفيذ التي أصدرها/أرفقها المكتب للعميل
        $execDocs = ExecutionDocument::whereHas('execution', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('path')
            ->latest('id')->get()
            ->map(fn (ExecutionDocument $ed) => [
                'id' => 'exec-'.$ed->id,
                'name' => $ed->label ?: basename((string) $ed->path),
                'meta' => 'مستند تنفيذ · '.($ed->doc_type ?: 'قرار 34/46'),
                'canDownload' => true,
                'downloadUrl' => route('documents.download-file', ['type' => 'exec', 'id' => $ed->id]),
                'at' => $ed->created_at?->getTimestamp() ?? 0,
            ]);

        // 4. مستندات التذاكر والاستشارات: ما أرفقه المكتب للعميل، وما رفعه هو بنفسه
        $ticketDocs = TicketDocument::whereHas('ticket', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('path')
            ->latest('id')->get()
            ->map(fn (TicketDocument $td) => [
                'id' => 'ticket-'.$td->id,
                'name' => $td->name,
                // مصدر المستند بحالته كما في `Lawyer\CaseController` — كان مستندُ العميل نفسه
                // يُعرض عليه «معتمد من المكتب»، وهو وصفٌ لمصدرٍ لم يُصدره
                'meta' => 'مستند استشارة · '.($td->doc_type ?: ($td->status === 'مرفق من المكتب' ? 'مرفق من المكتب' : 'مرفوع منك')),
                'canDownload' => true,
                'downloadUrl' => route('documents.download-file', ['type' => 'ticket', 'id' => $td->id]),
                'at' => $td->created_at?->getTimestamp() ?? 0,
            ]);

        // **دمجٌ زمنيّ لا رصٌّ تِباعاً.** كلّ مصدرٍ مرتَّبٌ وحده ثمّ `concat` يضعه خلف سابقه،
        // فمستندُ التذكرة الصادر الآن يظهر بعد **كلّ** مستندات القضايا والتنفيذ.
        $docsOut = $directOutDocs
            ->concat($caseDocs)
            ->concat($execDocs)
            ->concat($ticketDocs)
            ->sortByDesc('at')
            ->values();

        $docsUp = Document::where('user_id', $user->id)
            ->where('direction', 'up')
            ->latest('id')->get()
            ->map(fn (Document $d) => array_merge($d->toCard(), [
                'downloadUrl' => route('documents.download', $d->id),
            ]));

        return Inertia::render('documents', [
            'docsOut' => $docsOut,
            'docsUp' => $docsUp,
        ]);
    }

    // رفع مستند فعلي من العميل (يخزّن الملف على القرص + سجلّ يحمل المسار)
    public function store(Request $request): RedirectResponse
    {
        // قائمة السماح نفسها المعتمدة في بقيّة الرفوعات — كان هذا المسار (وإثبات السداد)
        // يقبل أي امتداد بما فيه التنفيذيّ والمضغوط، خلافاً لسياسة المشروع المعلنة.
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:'.self::ALLOWED_DOC_MIMES]], [
            'file.required' => 'يرجى اختيار ملف.',
            'file.file' => 'الملف غير صالح.',
            'file.mimes' => 'صيغة الملف غير مسموحة (المسموح: PDF أو صورة أو مستند Word).',
            'file.max' => 'حجم الملف يتجاوز الحدّ المسموح (2 ميجابايت).',
        ]);

        $file = $request->file('file');
        $path = $file->store("client-docs/{$request->user()->id}");
        $size = (int) $file->getSize();

        Document::create([
            'user_id' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'meta' => $this->metaLabel($file->getClientOriginalExtension(), $size),
            'direction' => 'up',
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $size,
        ]);

        return back()->with('success', 'تم رفع المستند.');
    }

    // تنزيل مستند مباشر من جدول documents
    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->authorize('view', $document);
        abort_if($document->path === null, 404, 'الملف غير موجود على الخادم.');

        return Storage::download($document->path, $document->name);
    }

    // تنزيل آمن لأي مستند صادرة (قضايا / تنفيذ / استشارات) خاص بالعميل
    public function downloadFile(Request $request): StreamedResponse
    {
        $user = $request->user();
        $type = $request->query('type');
        $id = $request->query('id');

        // الإدارة تصل هذا المسار عبر تجاوز EnsureRole (شاشة ملفّ العميل تبني روابطه)،
        // وكان الاستعلام يفلتر بمعرّف **الطالب** لا مالك المستند — فكل تنزيل إداريّ 404.
        // نُبقي حصر العميل بسجلّاته ونفتحها للإدارة صراحةً (إشراف موثّق، كـGate::before).
        $owner = fn ($q) => $user->isAdmin() ? $q : $q->where('user_id', $user->id);

        if ($type === 'case') {
            $doc = CaseDocument::whereHas('legalCase', $owner)->findOrFail($id);
            abort_unless($doc->path && Storage::exists($doc->path), 404, 'الملف غير موجود.');

            return Storage::download($doc->path, $doc->name);
        }

        if ($type === 'exec') {
            $doc = ExecutionDocument::whereHas('execution', $owner)->findOrFail($id);
            abort_unless($doc->path && Storage::exists($doc->path), 404, 'الملف غير موجود.');

            return Storage::download($doc->path, $doc->label ?: basename((string) $doc->path));
        }

        if ($type === 'ticket') {
            $doc = TicketDocument::whereHas('ticket', $owner)->findOrFail($id);
            abort_unless($doc->path && Storage::exists($doc->path), 404, 'الملف غير موجود.');

            return Storage::download($doc->path, $doc->name);
        }

        $doc = Document::when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))->findOrFail($id);
        abort_unless($doc->path && Storage::exists($doc->path), 404, 'الملف غير موجود.');

        return Storage::download($doc->path, $doc->name);
    }

    // وسم وصفيّ: الصيغة · الحجم (مثل: PDF · 1.2MB)
    private function metaLabel(string $ext, int $bytes): string
    {
        $mb = $bytes / 1048576;
        $sizeLabel = $mb >= 1 ? round($mb, 1).'MB' : max(1, (int) round($bytes / 1024)).'KB';

        return mb_strtoupper($ext ?: 'FILE').' · '.$sizeLabel;
    }
}
