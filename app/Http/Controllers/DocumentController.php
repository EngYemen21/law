<?php

namespace App\Http\Controllers;

use App\Models\CaseDocument;
use App\Models\Correspondence;
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

    // مستندات العميل الحالي: تجميع كافة المستندات والملفات الصادرة له من المكتب والقضايا والتنفيذ والمخاطبات
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
            ]);

        // 2. مستندات القضايا التي أرفقها/أصدرها المكتب للعميل
        $caseDocs = CaseDocument::whereHas('legalCase', fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')->get()
            ->map(fn (CaseDocument $cd) => [
                'id' => 'case-'.$cd->id,
                'name' => $cd->name,
                'meta' => 'مستند قضية · '.($cd->doc_type ?: 'معتمد من المكتب'),
                'canDownload' => ! empty($cd->path),
                'downloadUrl' => ! empty($cd->path) ? route('documents.download-file', ['type' => 'case', 'id' => $cd->id]) : null,
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
            ]);

        // 4. مستندات التذاكر والاستشارات المرفقة من المحامي/الإدارة للعميل
        $ticketDocs = TicketDocument::whereHas('ticket', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('path')
            ->latest('id')->get()
            ->map(fn (TicketDocument $td) => [
                'id' => 'ticket-'.$td->id,
                'name' => $td->name,
                'meta' => 'مستند استشارة · '.($td->doc_type ?: 'معتمد من المكتب'),
                'canDownload' => true,
                'downloadUrl' => route('documents.download-file', ['type' => 'ticket', 'id' => $td->id]),
            ]);

        // 5. المخاطبات والخطابات الرسمية الصادرة للعميل
        $correspondences = Correspondence::where('user_id', $user->id)
            ->latest('id')->get()
            ->map(fn (Correspondence $c) => [
                'id' => 'corr-'.$c->id,
                'name' => 'خطاب رسمي — '.$c->subject,
                'meta' => 'مخاطبة رسمية · رقم '.$c->number,
                'canDownload' => false,
                'downloadUrl' => null,
            ]);

        $docsOut = $directOutDocs
            ->concat($caseDocs)
            ->concat($execDocs)
            ->concat($ticketDocs)
            ->concat($correspondences)
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
        $request->validate(['file' => ['required', 'file', 'max:2048']], [
            'file.required' => 'يرجى اختيار ملف.',
            'file.file' => 'الملف غير صالح.',
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

        if ($type === 'case') {
            $doc = CaseDocument::whereHas('legalCase', fn ($q) => $q->where('user_id', $user->id))->findOrFail($id);
            abort_unless($doc->path && Storage::exists($doc->path), 404, 'الملف غير موجود.');

            return Storage::download($doc->path, $doc->name);
        }

        if ($type === 'exec') {
            $doc = ExecutionDocument::whereHas('execution', fn ($q) => $q->where('user_id', $user->id))->findOrFail($id);
            abort_unless($doc->path && Storage::exists($doc->path), 404, 'الملف غير موجود.');

            return Storage::download($doc->path, $doc->label ?: basename((string) $doc->path));
        }

        if ($type === 'ticket') {
            $doc = TicketDocument::whereHas('ticket', fn ($q) => $q->where('user_id', $user->id))->findOrFail($id);
            abort_unless($doc->path && Storage::exists($doc->path), 404, 'الملف غير موجود.');

            return Storage::download($doc->path, $doc->name);
        }

        $doc = Document::where('user_id', $user->id)->findOrFail($id);
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
