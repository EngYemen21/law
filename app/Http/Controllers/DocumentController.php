<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    // مستندات العميل الحالي مقسّمة إلى صادرة (out) ومرفوعة (up)
    public function index(Request $request): Response
    {
        $documents = Document::where('user_id', $request->user()->id)
            ->latest('id')->get();

        return Inertia::render('documents', [
            'docsOut' => $documents->where('direction', 'out')->values()
                ->map(fn (Document $d) => $d->toCard()),
            'docsUp' => $documents->where('direction', 'up')->values()
                ->map(fn (Document $d) => $d->toCard()),
        ]);
    }

    // رفع مستند فعلي من العميل (يخزّن الملف على القرص + سجلّ يحمل المسار) — يطابق نمط TicketController::attach
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:2048']], [ // حتى 2MB (يطابق upload_max_filesize)
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

    // تنزيل مستند محميّ عبر الصلاحيات (DocumentPolicy) وله ملفّ فعليّ
    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->authorize('view', $document);
        abort_if($document->path === null, 404, 'الملف غير موجود على الخادم.');

        return Storage::download($document->path, $document->name);
    }

    // وسم وصفيّ: الصيغة · الحجم (مثل: PDF · 1.2MB)
    private function metaLabel(string $ext, int $bytes): string
    {
        $mb = $bytes / 1048576;
        $sizeLabel = $mb >= 1 ? round($mb, 1).'MB' : max(1, (int) round($bytes / 1024)).'KB';

        return mb_strtoupper($ext ?: 'FILE').' · '.$sizeLabel;
    }
}
