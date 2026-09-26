<?php

namespace App\Support;

use App\Enums\RequirementCheck;
use App\Events\TicketMessageBroadcast;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\TicketDocumentRequirement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * **«النواقص» لكلّ تذكرة — مصدرٌ واحد** (قرار المالك 2026-09-26).
 *
 * النواقص = بنود قائمة مستندات القسم (`LegalCatalogue::documentsFor`) التي لم يستوفها مرفقٌ بعد.
 * والاستيفاء مطابقةٌ محفوظة (`ticket_document_requirements`) يكتبها أحد اثنين:
 *   - فحص المستند الآليّ (`document.analyze`) حين يقرأ محتوى الملفّ فيجده بنداً من القائمة؛
 *   - أو موظّفٌ/محامٍ/الإدارة يدوياً — حين يتعذّر الفحص أو يخطئ.
 *
 * **لا استيفاء بافتراض**: ملفٌّ لم يُفحص (الذكاء مطفأ أو تعذّر) لا يُعدّ مستوفياً لشيء، ويُعرض للطاقم
 * «لم يُتحقّق» ليحكم فيه. وكلّ رسالة تطلب مستندات من العميل تُبنى من `missing()` هنا — فلا يُطلب
 * منه ثانيةً ما ثبت إرفاقه، ولا يُعاد بناء قائمةٍ في موضعٍ آخر (كان الغلاف نفسه منسوخاً خمس مرّات).
 *
 * والعميل لا يرى من هذا إلّا ما بقي مطلوباً: لا مصدر المطابقة ولا حكم النموذج.
 */
class TicketDocumentRequirements
{
    /**
     * تنويهٌ يرافق القائمة حيثما عُرضت على العميل: قائمةُ قسمٍ لا حكمٌ نهائيّ على ملفّه.
     * (كان `ServiceDocs::NOTE`؛ وصار يقول إنّ ما ثبت إرفاقه أُسقط منها.)
     */
    public const NOTE = 'قائمةٌ بحسب قسم طلبك، أُسقط منها ما ثبت إرفاقه، وقد يطلب المستشار غيرها بعد الاطّلاع.';

    /** حين في الملفّ مرفقاتٌ لم يُتحقّق منها بعد — كي لا يُعيد العميل إرفاق ما أرسله. */
    public const UNCHECKED_NOTE = 'إن كنت أرفقت بعضها فلا حاجة لإعادة إرفاقه، وسيتحقّق منه الفريق.';

    /** حين لا ناقص في القائمة — لا تُرسل قائمةٌ فارغة ولا يُطلب ما وصل. */
    public const COMPLETE_TEXT = 'مستندات القائمة الأساسيّة لطلبك مرفقةٌ لدينا، وسيُبلغك الفريق إن احتاج غيرها.';

    /** وسم البند الاختياريّ حيثما عُرض — للعميل وللنموذج. */
    public const OPTIONAL_LABEL = 'اختياريّ';

    /**
     * قائمة القسم الذي تتبعه التذكرة. التذكرة القديمة بلا معرّف قسم يُجرَّب نوعُها (كان مفتاح القوائم القديمة).
     *
     * @return list<array{id: int|null, name: string, required: bool}>
     */
    public static function listFor(Ticket $ticket): array
    {
        $departmentId = $ticket->legal_department_id ?? LegalCatalogue::resolveDepartment((string) $ticket->type)?->id;

        return LegalCatalogue::documentsFor($departmentId);
    }

    /**
     * حالة كلّ بند: مستوفى أم لا، وبأيّ مرفق، ومن حكم به. الإلزاميّ أوّلاً بترتيب القائمة.
     *
     * @return list<array{id: int|null, name: string, required: bool, satisfied: bool, matchedDocumentId: int|null, matchedDocumentName: string|null, checkedBy: string|null}>
     */
    public static function for(Ticket $ticket): array
    {
        $matches = self::matchesOn($ticket);

        $status = array_map(function (array $item) use ($matches) {
            $match = self::matchFor($item, $matches);

            return $item + [
                'satisfied' => $match !== null,
                'matchedDocumentId' => $match?->ticket_document_id,
                'matchedDocumentName' => $match?->document?->name,
                'checkedBy' => $match?->checked_by->value,
            ];
        }, self::listFor($ticket));

        return self::requiredFirst($status);
    }

    /**
     * ما بقي مطلوباً — الإلزاميّ أوّلاً ثمّ الاختياريّ.
     *
     * @return list<array{name: string, required: bool}>
     */
    public static function missing(Ticket $ticket): array
    {
        return array_values(array_map(
            fn (array $item) => ['name' => $item['name'], 'required' => $item['required']],
            array_filter(self::for($ticket), fn (array $item) => ! $item['satisfied']),
        ));
    }

    /**
     * مرفقات التذكرة التي لم تُفحص مقابل القائمة — لا آلياً ولا يدوياً.
     *
     * @return Collection<int, TicketDocument>
     */
    public static function uncheckedDocuments(Ticket $ticket): Collection
    {
        return $ticket->documents()->whereNull('requirements_checked_at')->orderBy('id')->get(['id', 'name']);
    }

    /** أسماء النواقص للنصّ المولَّد (الترحيب) — والاختياريّ موسوم. */
    public static function labels(array $missing): array
    {
        return array_map(fn (array $d) => $d['required'] ? $d['name'] : $d['name'].' ('.self::OPTIONAL_LABEL.')', $missing);
    }

    /**
     * القائمة كما تُعرض على فحص المستند الآليّ — بإلزامها واستيفائها، كي يطابق النموذج الملفّ ببندٍ
     * منها باسمه الحرفيّ، ويعرف ما وصل فلا يعدّه ناقصاً.
     */
    public static function forPrompt(Ticket $ticket): string
    {
        return implode("\n", array_map(
            fn (array $item) => '- '.$item['name'].' — '.($item['required'] ? 'إلزاميّ' : self::OPTIONAL_LABEL)
                .($item['satisfied'] ? ' — مستوفًى بمرفقٍ سابق' : ''),
            self::for($ticket),
        ));
    }

    /**
     * **ما يقبله الخادم من أسماء النموذج**: بندٌ من القائمة بعينه بعد توحيد الصياغة — لا تقريب ولا احتواء.
     * اسمٌ لا يطابق بنداً يُسقط: النموذج لا يُضيف إلى قائمة القسم ولا يحكم بما ليس فيها.
     *
     * @param  mixed  $names  مخرج النموذج كما جاء (قائمة أسماء أو نصّ أو لا شيء)
     * @param  list<array{id: int|null, name: string, required: bool}>  $items
     * @return list<array{id: int|null, name: string, required: bool}>
     */
    public static function matchItems(mixed $names, array $items): array
    {
        $names = is_string($names) ? [$names] : (is_array($names) ? $names : []);
        $byKey = [];
        foreach ($items as $item) {
            $byKey[LegalCatalogue::foldName($item['name'])] = $item;
        }

        $matched = [];
        foreach ($names as $name) {
            $key = is_string($name) ? LegalCatalogue::foldName($name) : '';
            if ($key !== '' && isset($byKey[$key])) {
                $matched[$key] = $byKey[$key];
            }
        }

        return array_values($matched);
    }

    /**
     * **يحفظ حصيلة الفحص الآليّ للمستند** مقابل قائمة قسمه: يُعلَّم الملفّ مفحوصاً، ويُضاف ما طابقه
     * من بنود. لا يُمسّ ما أكّده الطاقم (المطابقة القائمة للبند نفسه تبقى كما هي).
     *
     * @param  mixed  $names  أسماء البنود كما أعادها النموذج — تُصفّى بـ`matchItems`
     */
    public static function recordAiCheck(TicketDocument $document, mixed $names): void
    {
        $ticket = $document->ticket;
        $matched = self::matchItems($names, self::listFor($ticket));

        DB::transaction(function () use ($document, $matched) {
            foreach ($matched as $item) {
                TicketDocumentRequirement::firstOrCreate(
                    ['ticket_document_id' => $document->id, 'requirement' => $item['name']],
                    ['legal_department_document_id' => $item['id'], 'checked_by' => RequirementCheck::Ai],
                );
            }

            $document->update(['requirements_checked_at' => now()]);
        });
    }

    /**
     * **تأكيدٌ يدويّ**: هذا المرفق يستوفي هذا البند. يُكتب فوق حكم النموذج للمرفق نفسه، ويُقيَّد
     * ملاحظةً داخليّة باسم من أكّد.
     */
    public static function markByStaff(Ticket $ticket, string $requirement, TicketDocument $document, User $actor): void
    {
        $item = self::itemNamed($ticket, $requirement);

        if ((int) $document->ticket_id !== (int) $ticket->id) {
            throw ValidationException::withMessages(['document_id' => 'المستند ليس من مرفقات هذه التذكرة.']);
        }

        DB::transaction(function () use ($document, $item, $actor) {
            TicketDocumentRequirement::updateOrCreate(
                ['ticket_document_id' => $document->id, 'requirement' => $item['name']],
                ['legal_department_document_id' => $item['id'], 'checked_by' => RequirementCheck::Staff, 'checked_by_user_id' => $actor->id],
            );

            if ($document->requirements_checked_at === null) {
                $document->update(['requirements_checked_at' => now()]);
            }
        });

        self::note($ticket, $actor, "أكّد {$actor->name} أنّ المرفق «{$document->name}» يستوفي «{$item['name']}» من قائمة مستندات القسم.");
    }

    /** **إلغاء الاستيفاء** — آلياً كان أو يدوياً: البند يعود ناقصاً ويُطلب إن طُلبت النواقص. */
    public static function unmark(Ticket $ticket, string $requirement, User $actor): void
    {
        $item = self::itemNamed($ticket, $requirement);
        $matches = self::matchesOn($ticket)->filter(fn (TicketDocumentRequirement $m) => self::matchesItem($m, $item));

        if ($matches->isEmpty()) {
            return;
        }

        TicketDocumentRequirement::whereKey($matches->modelKeys())->delete();

        self::note($ticket, $actor, "ألغى {$actor->name} استيفاء «{$item['name']}» — يعود ناقصاً في قائمة مستندات القسم.");
    }

    /**
     * ما تعرضه قائمة الطاقم في جانب التذكرة، ومودال طلب النواقص.
     *
     * @return array{department: string|null, usesDefault: bool, items: list<array<string, mixed>>, documents: list<array{id: int, name: string, checked: bool}>, uncheckedCount: int}
     */
    public static function toData(Ticket $ticket): array
    {
        $items = array_map(fn (array $item) => [
            'name' => $item['name'],
            'required' => $item['required'],
            'satisfied' => $item['satisfied'],
            'document' => $item['matchedDocumentId'] === null ? null : ['id' => $item['matchedDocumentId'], 'name' => $item['matchedDocumentName']],
            'checkedBy' => $item['checkedBy'],
            'checkedByLabel' => $item['checkedBy'] === null ? null : RequirementCheck::from($item['checkedBy'])->label(),
        ], self::for($ticket));

        $documents = $ticket->documents()->orderBy('id')->get(['id', 'name', 'requirements_checked_at']);

        return [
            'department' => $ticket->department ?: null,
            // بنود القائمة العامّة بلا معرّف — القسم لم تُحرَّر قائمته بعد
            'usesDefault' => collect(self::listFor($ticket))->every(fn (array $i) => $i['id'] === null),
            'items' => $items,
            'documents' => $documents->map(fn (TicketDocument $d) => [
                'id' => $d->id,
                'name' => (string) $d->name,
                'checked' => $d->requirements_checked_at !== null,
            ])->values()->all(),
            'uncheckedCount' => $documents->whereNull('requirements_checked_at')->count(),
        ];
    }

    // ── العرض للعميل: الغلاف الواحد لقوائم المستندات ─────────────

    /**
     * **شرائح المستندات** — الغلاف الوحيد (`doc-chip`) لكلّ قائمة مستنداتٍ تُطلب في رسالة.
     * الأسماء تُهرَّب واحداً واحداً؛ والغلاف حرفيّ ثابت، فلا HTML يصل من مُدخلٍ.
     *
     * @param  list<string|array{name: string, required: bool}>  $items
     */
    public static function chips(array $items): string
    {
        return implode('', array_map(function (string|array $item) {
            $name = is_array($item) ? $item['name'] : $item;
            $optional = is_array($item) && ! $item['required'];

            return '<span class="doc-chip">'.e($name).($optional ? ' ('.self::OPTIONAL_LABEL.')' : '').'</span>';
        }, $items));
    }

    /**
     * **جسم رسالة طلب النواقص من قائمة القسم** — مقدّمةٌ، ثمّ الإلزاميّ، ثمّ الاختياريّ موسوماً، ثمّ التنويه.
     * لا ناقص ⇒ لا قائمة فارغة: يُقال إنّ الأساسيّ وصل.
     *
     * @param  string|null  $intro  نصٌّ ثابت من الشيفرة (يُهرَّب هنا) قبل القائمة — `null` حين يطلبها `$lead` نفسه (الترحيب)
     * @param  string  $lead  HTML مبنيّ ومهرَّب سلفاً يسبق المقدّمة (حكم فحص مستند، أو الترحيب) — اختياريّ
     */
    public static function requestHtml(Ticket $ticket, ?string $intro, string $lead = ''): string
    {
        $missing = self::missing($ticket);
        if ($missing === []) {
            return $lead.'<p>'.e(self::COMPLETE_TEXT).'</p>';
        }

        $required = array_values(array_filter($missing, fn (array $d) => $d['required']));
        $optional = array_values(array_filter($missing, fn (array $d) => ! $d['required']));

        $html = $lead.($intro === null ? '' : '<p>'.e($intro).'</p>');
        if ($required !== []) {
            $html .= '<div class="doc-list">'.self::chips($required).'</div>';
        }
        if ($optional !== []) {
            $html .= ($required !== [] ? '<p class="muted">وإن توفّر لديك:</p>' : '').'<div class="doc-list">'.self::chips($optional).'</div>';
        }

        $html .= '<p class="muted">'.e(self::NOTE).'</p>';
        if (self::uncheckedDocuments($ticket)->isNotEmpty()) {
            $html .= '<p class="muted">'.e(self::UNCHECKED_NOTE).'</p>';
        }

        return $html;
    }

    // ── داخليّ ───────────────────────────────────────────

    /** @return Collection<int, TicketDocumentRequirement> مطابقات مرفقات التذكرة — المؤكَّد يدوياً أوّلاً */
    private static function matchesOn(Ticket $ticket): Collection
    {
        return TicketDocumentRequirement::query()
            ->whereIn('ticket_document_id', $ticket->documents()->select('id'))
            ->with('document:id,name')
            ->get()
            ->sortBy(fn (TicketDocumentRequirement $m) => [$m->checked_by === RequirementCheck::Staff ? 0 : 1, $m->id])
            ->values();
    }

    /**
     * @param  array{id: int|null, name: string, required: bool}  $item
     * @param  Collection<int, TicketDocumentRequirement>  $matches
     */
    private static function matchFor(array $item, Collection $matches): ?TicketDocumentRequirement
    {
        return $matches->first(fn (TicketDocumentRequirement $m) => self::matchesItem($m, $item));
    }

    /**
     * المطابقة تتبع البند بمعرّفه (فتبقى بعد إعادة تسميته)، وإلّا باسمه (بنود القائمة العامّة، أو بندٌ
     * حُذف ثمّ أُعيد، أو قسمٌ تغيّر للتذكرة وفي قائمته الجديدة البند نفسه).
     *
     * @param  array{id: int|null, name: string, required: bool}  $item
     */
    private static function matchesItem(TicketDocumentRequirement $match, array $item): bool
    {
        if ($item['id'] !== null && $match->legal_department_document_id === $item['id']) {
            return true;
        }

        return LegalCatalogue::foldName($match->requirement) === LegalCatalogue::foldName($item['name']);
    }

    /** @return array{id: int|null, name: string, required: bool} */
    private static function itemNamed(Ticket $ticket, string $requirement): array
    {
        $item = self::matchItems([$requirement], self::listFor($ticket))[0] ?? null;

        if ($item === null) {
            throw ValidationException::withMessages(['requirement' => 'ليس هذا البند في قائمة مستندات قسم التذكرة.']);
        }

        return $item;
    }

    /**
     * الإلزاميّ أوّلاً مع حفظ ترتيب القائمة داخل كلٍّ منهما.
     *
     * @template T of array{required: bool}
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private static function requiredFirst(array $items): array
    {
        return array_merge(
            array_values(array_filter($items, fn (array $i) => $i['required'])),
            array_values(array_filter($items, fn (array $i) => ! $i['required'])),
        );
    }

    /** ملاحظةٌ داخليّة للطاقم (لا تصل العميل — `who = note`). */
    private static function note(Ticket $ticket, User $actor, string $text): void
    {
        $now = now();
        $msg = $ticket->messages()->create([
            'who' => 'note',
            'name' => $actor->name,
            'role' => 'قائمة المستندات',
            'body' => '<p>'.e($text).'</p>',
            'time_label' => $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م'),
        ]);
        Live::push(new TicketMessageBroadcast($msg));
    }
}
