<?php

namespace App\Http\Controllers\Lawyer;

use App\Http\Controllers\Controller;
use App\Models\CaseDocument;
use App\Models\TicketDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تنزيل مستندات القضايا والتذاكر للمحامي المسنَد.
 *
 * لماذا: لم يكن في المنظومة مسار تنزيل للطاقم إطلاقاً — المحامي المسنَد يرى على شاشته
 * أنّ مستنداً رُفع ويقرأ ملخّصه الذكيّ ولا يستطيع فتح الملف نفسه. المساران الوحيدان
 * القائمان (`documents.download*`) داخل مجموعة `role:client` ومحصوران بسجلّات الطالب.
 *
 * السياسة (قرار المنتج): **المحامي المسنَد وحده**، والإدارة إشرافاً. الموظف لا يفتح
 * ولا ينزّل مستندات القضايا والتذاكر ولو رآها في القوائم.
 */
class DocumentController extends Controller
{
    public function download(Request $request, string $type, int $id): StreamedResponse
    {
        $user = $request->user();

        abort_unless(in_array($type, ['case', 'ticket'], true), 404);

        if ($type === 'case') {
            $doc = CaseDocument::with('legalCase')->findOrFail($id);
            $assignedTo = $doc->legalCase?->assigned_lawyer_id;
            $name = $doc->name;
        } else {
            $doc = TicketDocument::with('ticket')->findOrFail($id);
            $assignedTo = $doc->ticket?->assigned_lawyer_id;
            $name = $doc->name;
        }

        // الإدارة تمرّ إشرافاً (تصل هنا عبر تجاوز EnsureRole)، والمحامي بإسناده وحده.
        abort_unless($user->isAdmin() || ($user->isLawyer() && $assignedTo === $user->id), 403);

        abort_if($doc->path === null, 404, 'الملف غير موجود.');
        abort_unless(Storage::exists($doc->path), 404, 'الملف غير موجود على الخادم.');

        return Storage::download($doc->path, $name);
    }
}
