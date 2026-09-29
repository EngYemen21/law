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

        /*
         * **ما رفعه العميل بنفسه في «مستنداتك المرفوعة»، والصادرة للمكتب وحده** (قرار المالك 2026-09-29).
         * كانت مرفقات العميل من محادثات القضيّة والتذكرة والتنفيذ تُعرض «صادرةً إليك» — ومرفق التنفيذ
         * بوسم «قرار 34/46» كأنّ المكتب أصدره (ثبت باختبار). المصدر في كلّ نوعٍ سؤالٌ واحد: `isFromClient()`.
         */
        $row = fn (string $key, int $id, string $name, string $meta, ?string $path, string $type, mixed $at) => [
            'id' => "{$key}-{$id}",
            'name' => $name,
            'meta' => $meta,
            'canDownload' => ! empty($path),
            'downloadUrl' => ! empty($path) ? route('documents.download-file', ['type' => $type, 'id' => $id]) : null,
            'at' => $at?->getTimestamp() ?? 0,
        ];

        // 2. مستندات القضايا
        $caseDocs = CaseDocument::whereHas('legalCase', fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')->get()
            ->map(fn (CaseDocument $cd) => ['mine' => $cd->isFromClient()] + $row(
                'case', $cd->id, (string) $cd->name,
                'مستند قضية · '.($cd->doc_type ?: ($cd->isFromClient() ? 'مرفوع منك' : 'معتمد من المكتب')),
                $cd->path, 'case', $cd->created_at,
            ));

        // 3. مستندات التنفيذ
        $execDocs = ExecutionDocument::whereHas('execution', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('path')
            ->latest('id')->get()
            ->map(fn (ExecutionDocument $ed) => ['mine' => $ed->isFromClient()] + $row(
                'exec', $ed->id, (string) ($ed->label ?: basename((string) $ed->path)),
                'مستند تنفيذ · '.($ed->doc_type ?: ($ed->isFromClient() ? 'مرفوع منك' : 'مرفق من المكتب')),
                $ed->path, 'exec', $ed->created_at,
            ));

        // 4. مستندات التذاكر والاستشارات
        $ticketDocs = TicketDocument::whereHas('ticket', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('path')
            ->latest('id')->get()
            ->map(fn (TicketDocument $td) => ['mine' => $td->isFromClient()] + $row(
                'ticket', $td->id, (string) $td->name,
                'مستند استشارة · '.($td->doc_type ?: ($td->isFromClient() ? 'مرفوع منك' : 'مرفق من المكتب')),
                $td->path, 'ticket', $td->created_at,
            ));

        $linked = $caseDocs->concat($execDocs)->concat($ticketDocs);
        $strip = fn (array $d) => array_diff_key($d, ['mine' => true]);

        // **دمجٌ زمنيّ لا رصٌّ تِباعاً** — كلّ مصدرٍ مرتَّبٌ وحده، و`concat` كان يضع الأحدث خلف كلّ سابقه
        $docsOut = $directOutDocs
            ->concat($linked->reject(fn ($d) => $d['mine'])->map($strip))
            ->sortByDesc('at')
            ->values();

        $docsUp = Document::where('user_id', $user->id)
            ->where('direction', 'up')
            ->latest('id')->get()
            ->map(fn (Document $d) => array_merge($d->toCard(), [
                'downloadUrl' => route('documents.download', $d->id),
                'at' => $d->created_at?->getTimestamp() ?? 0,
            ]))
            ->concat($linked->filter(fn ($d) => $d['mine'])->map($strip))
            ->sortByDesc('at')
            ->values();

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
