<?php

namespace App\Http\Controllers;

use App\Support\ConversationFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تنزيلُ مرفقات المحادثات (تذكرة · قضيّة · تنفيذ) — مسارٌ واحدٌ لكلّ الأدوار.
 * القاعدة كلّها في `ConversationFiles::canDownload`؛ هنا التنفيذ وحده.
 */
class ConversationFileController extends Controller
{
    public function __invoke(Request $request, string $type, int $id): StreamedResponse
    {
        $doc = ConversationFiles::find($type, $id);

        abort_unless(ConversationFiles::canDownload($request->user(), $doc), 403, 'لا تملك صلاحية تنزيل هذا المستند.');
        abort_if($doc->path === null || ! Storage::exists($doc->path), 404, 'الملف غير موجود على الخادم.');

        return Storage::download($doc->path, ConversationFiles::nameOf($doc) ?: basename((string) $doc->path));
    }
}
