<?php

namespace App\Support;

use App\Enums\DocumentDirection;
use App\Models\CaseDocument;
use App\Models\Document;
use App\Models\ExecutionDocument;
use App\Models\TicketDocument;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * **مستندات العميل — مصدرٌ واحد لـ«المستندات» وللرئيسيّة.**
 *
 * كانت الرئيسيّة تقرأ جدول `documents` وحده بلا تمييز اتّجاه، فتعرض تحت «أحدث الوثائق الصادرة» ما رفعه
 * العميل نفسه، وتغفل الصادر إليه في قضاياه وتذاكره وتنفيذه (جرد تبويبات العميل 2026-10-04). الآن تقرأ
 * الصفحتان التجميع نفسه: `out` الصادر من المكتب، و`up` ما رفعه العميل — مرتّبَين زمنيّاً.
 */
final class ClientDocuments
{
    /** @return array{out: Collection<int, array<mixed>>, up: Collection<int, array<mixed>>} */
    public static function for(User $client): array
    {
        // 1. المستندات الصادرة المباشرة من جدول documents
        $directOutDocs = Document::where('user_id', $client->id)
            ->where('direction', DocumentDirection::Out)
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
        $caseDocs = CaseDocument::whereHas('legalCase', fn ($q) => $q->where('user_id', $client->id))
            ->latest('id')->get()
            ->map(fn (CaseDocument $cd) => ['mine' => $cd->isFromClient()] + $row(
                'case', $cd->id, (string) $cd->name,
                'مستند قضية · '.($cd->doc_type ?: ($cd->isFromClient() ? 'مرفوع منك' : 'معتمد من المكتب')),
                $cd->path, 'case', $cd->created_at,
            ));

        // 3. مستندات التنفيذ
        $execDocs = ExecutionDocument::whereHas('execution', fn ($q) => $q->where('user_id', $client->id))
            ->whereNotNull('path')
            ->latest('id')->get()
            ->map(fn (ExecutionDocument $ed) => ['mine' => $ed->isFromClient()] + $row(
                'exec', $ed->id, (string) ($ed->label ?: basename((string) $ed->path)),
                'مستند تنفيذ · '.($ed->doc_type ?: ($ed->isFromClient() ? 'مرفوع منك' : 'مرفق من المكتب')),
                $ed->path, 'exec', $ed->created_at,
            ));

        // 4. مستندات التذاكر والاستشارات
        $ticketDocs = TicketDocument::whereHas('ticket', fn ($q) => $q->where('user_id', $client->id))
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

        $docsUp = Document::where('user_id', $client->id)
            ->where('direction', DocumentDirection::Up)
            ->latest('id')->get()
            ->map(fn (Document $d) => array_merge($d->toCard(), [
                'downloadUrl' => route('documents.download', $d->id),
                'at' => $d->created_at?->getTimestamp() ?? 0,
            ]))
            ->concat($linked->filter(fn ($d) => $d['mine'])->map($strip))
            ->sortByDesc('at')
            ->values();

        return ['out' => $docsOut, 'up' => $docsUp];
    }
}
