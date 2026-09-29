<?php

namespace App\Support;

use App\Models\LegalCase;
use App\Models\TicketDocument;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * **مرفقات الطلب قبل التحويل — جزءٌ من ملفّ القضيّة لكلّ من يراه** (ملاحظة المالك 2026-09-27).
 *
 * `CaseConversion` لا ينسخ مستندات التذكرة إلى القضيّة، وكانت شاشة المحامي وحدها تقرؤها من التذكرة:
 * فتبدأ قضيّةٌ محوّلة **فارغةً** عند الإدارة والعميل، والمستندات التي بُني عليها القرار لا تظهر لهما.
 * والمحامي كان يراها ومعها ما رفضه الفحص («غير مرتبط») محسوباً في جاهزيّة اللائحة.
 *
 * **تُقرأ ولا تُنسخ:** مصدرٌ واحد هو التذكرة — لا ملفّان على القرص، ولا مستندٌ يظهر مرّتين في شاشة
 * المحامي التي تجمع النوعين. والتحميل بحارس محادثة التذكرة نفسه (`ConversationFiles`).
 */
final class CaseTicketDocuments
{
    /** ما رفضه فحص المستند لأنه لا يخصّ الطلب — لا يدخل ملفّ القضيّة. */
    public const UNRELATED = 'غير مرتبط';

    /** @return Collection<int, array<string, mixed>> الأحدث أوّلاً */
    public static function for(LegalCase $case, ?User $viewer): Collection
    {
        $ticket = $case->ticket;
        if ($ticket === null) {
            return collect();
        }

        return $ticket->documents
            ->reject(fn (TicketDocument $d) => $d->status === self::UNRELATED)
            ->sortByDesc('id')
            ->values()
            ->map(fn (TicketDocument $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'by' => $d->isFromClient() ? 'العميل' : 'المكتب',
                'status' => (string) $d->status,
                'docType' => (string) ($d->doc_type ?? ''),
                'summary' => (string) ($d->summary ?? ''),
                'date' => $d->created_at?->locale('ar')->translatedFormat('d F Y') ?: '',
                'downloadUrl' => $d->path && $viewer !== null && ConversationFiles::canDownload($viewer, $d) ? ConversationFiles::url('ticket', $d->id) : null,
            ]);
    }
}
