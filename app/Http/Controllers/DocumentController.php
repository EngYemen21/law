<?php

namespace App\Http\Controllers;

use App\Enums\DocumentDirection;
use App\Models\CaseDocument;
use App\Models\Document;
use App\Models\ExecutionDocument;
use App\Models\TicketDocument;
use App\Support\ClientDocuments;
use App\Support\UploadLimits;
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

    // مستندات العميل الحالي — التجميع في مصدرٍ واحد تقرؤه الرئيسيّة أيضاً (`ClientDocuments`)
    public function index(Request $request): Response
    {
        $docs = ClientDocuments::for($request->user());

        return Inertia::render('documents', [
            'docsOut' => $docs['out'],
            'docsUp' => $docs['up'],
        ]);
    }

    // رفع مستند فعلي من العميل (يخزّن الملف على القرص + سجلّ يحمل المسار)
    public function store(Request $request): RedirectResponse
    {
        // قائمة السماح نفسها المعتمدة في بقيّة الرفوعات — كان هذا المسار (وإثبات السداد)
        // يقبل أي امتداد بما فيه التنفيذيّ والمضغوط، خلافاً لسياسة المشروع المعلنة.
        $request->validate(['file' => ['required', 'file', UploadLimits::rule(UploadLimits::DOCUMENT_KB), 'mimes:'.self::ALLOWED_DOC_MIMES]], [
            'file.required' => 'يرجى اختيار ملف.',
            'file.file' => 'الملف غير صالح.',
            'file.mimes' => 'صيغة الملف غير مسموحة (المسموح: PDF أو صورة أو مستند Word).',
            'file.max' => 'حجم الملف يتجاوز الحدّ المسموح ('.UploadLimits::label(UploadLimits::DOCUMENT_KB).').',
        ]);

        $file = $request->file('file');
        $path = $file->store("client-docs/{$request->user()->id}");
        $size = (int) $file->getSize();

        Document::create([
            'user_id' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'meta' => $this->metaLabel($file->getClientOriginalExtension(), $size),
            'direction' => DocumentDirection::Up,
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
