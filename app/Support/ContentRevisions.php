<?php

namespace App\Support;

use App\Models\ContentRevision;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * **سجلّ نسخ التحليلات والملخّصات** (طلب المالك 2026-09-29).
 *
 * كلّ تغييرٍ في نصٍّ مراقَب (`TracksRevisions::revisionKinds`) يُحفظ نسخةً كاملة: رقمها، ومصدرها، ومن
 * كتبها أو أطلقها، ودوره، وعنوانه، ووقتها. الالتقاط في النموذج لا في المتحكّمات — النصوص تُكتب من
 * عشرات المواضع (مهامّ الذكاء، سحب Zoom، القوالب، تعديلات الطاقم، الانتقالات)، وأيّ موضعٍ جديد يرث
 * التسجيل بلا سطرٍ إضافيّ.
 *
 * **المصدر:** نصٌّ يُكتب في طلب مستخدمٍ حيّ = «بشريّ» باسمه؛ وفي مهمّة طابور أو أمرٍ بلا مستخدم = «ذكاء
 * اصطناعي». ومخرجات الآلة التي تُكتب **داخل** طلب مستخدم (سحب ملخّص Zoom، القالب، التحليل المتزامن)
 * تُلفّ بـ`machine()` فتُنسب لمصدرها، ويُذكر من أطلقها.
 *
 * **ما قبل السجلّ:** أوّل تعديلٍ على نصٍّ بلا نسخٍ يحفظ نصّه السابق أوّلاً بمصدر «baseline» — فلا يضيع.
 */
final class ContentRevisions
{
    /** أنواع النسخ وتسمياتها — مفتاح النوع في `revisionKinds` والمسار والواجهة. */
    public const KINDS = [
        'ticket_summary' => 'ملخّص التذكرة',
        'ticket_result' => 'الرأي القانوني المنشور',
        'consult_analysis' => 'تحليل طلب الاستشارة',
        'consult_summary' => 'ملخّص جلسة الاستشارة',
        'consult_notes' => 'ملاحظات الجلسة',
        'case_pleading' => 'مسودّة لائحة الدعوى',
        'case_classification' => 'تصنيف القضية الآليّ',
        'exec_study' => 'دراسة طلب التنفيذ',
        'meeting_summary' => 'ملخّص الاجتماع',
        'meeting_minutes' => 'محضر الاجتماع',
    ];

    public const SOURCES = [
        'ai' => 'ذكاء اصطناعي',
        'zoom' => 'ملخّص Zoom',
        'template' => 'قالب آليّ',
        'human' => 'تعديل بشريّ',
        'baseline' => 'قبل تفعيل السجلّ',
    ];

    /** مصدر الآلة الجاري داخل طلب مستخدم — مكدّسٌ في الحاوية (جديدٌ لكلّ طلبٍ واختبار). */
    private const MACHINE = 'content_revisions.machine';

    /**
     * يُنفّذ `$write` ونصوصه منسوبةٌ لمصدر آليّ (`zoom`/`template`/`ai`) وإن كُتبت في طلب مستخدم.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public static function machine(string $source, Closure $write): mixed
    {
        $stack = app()->bound(self::MACHINE) ? (array) app(self::MACHINE) : [];
        app()->instance(self::MACHINE, [...$stack, $source]);

        try {
            return $write();
        } finally {
            app()->instance(self::MACHINE, $stack);
        }
    }

    /** يلتقط ما تغيّر من نصوص النموذج المراقَبة بعد حفظه (`TracksRevisions`). */
    public static function capture(Model $model): void
    {
        if (! method_exists($model, 'revisionKinds') || ! method_exists($model, 'revisionOwner')) {
            return;
        }
        $owner = $model->revisionOwner();
        if (! $owner instanceof Model) {
            return;
        }

        foreach ($model->revisionKinds() as $kind => $fields) {
            $current = self::snapshot(fn (string $f) => $model->getAttribute($f), $fields);
            $changed = $model->wasRecentlyCreated
                ? self::hasText($current)
                : array_intersect($fields, array_keys($model->getChanges())) !== [];
            if (! $changed) {
                continue;
            }

            $query = ContentRevision::where('subject_type', class_basename($owner))->where('subject_id', $owner->getKey())->where('kind', $kind);
            // نصٌّ سابقٌ لم يُسجَّل (قبل تفعيل السجلّ) يُحفظ أوّلاً — فلا يمحوه أوّل تعديل
            if (! $model->wasRecentlyCreated && ! $query->exists()) {
                $before = self::snapshot(fn (string $f) => $model->getOriginal($f), $fields);
                if (self::hasText($before)) {
                    self::write($owner, $kind, $before, 'baseline', null);
                }
            }

            $last = $query->orderByDesc('version')->first();
            if ($last !== null && self::same($last->content, $current)) {
                continue; // حفظٌ بلا تغييرٍ في النصّ — لا نسخة مكرّرة
            }

            [$source, $actor] = self::author();
            self::write($owner, $kind, $current, $source, $actor);
        }
    }

    /**
     * نسخ نوعٍ لملفٍّ واحد — الأحدث أوّلاً.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function history(Model $owner, string $kind): array
    {
        return ContentRevision::where('subject_type', class_basename($owner))->where('subject_id', $owner->getKey())
            ->where('kind', $kind)->orderByDesc('version')->get()
            ->map(function (ContentRevision $r) {
                $at = $r->created_at->copy();
                $at->locale('ar');

                return [
                    'version' => $r->version,
                    'source' => $r->source,
                    'sourceLabel' => self::SOURCES[$r->source] ?? $r->source,
                    'actor' => $r->actor_name,
                    'role' => $r->actor_role,
                    'ip' => $r->ip,
                    'at' => $at->translatedFormat('d F Y — h:i a'),
                    'content' => $r->content,
                ];
            })->values()->all();
    }

    /** @return array{0: string, 1: User|null} المصدر، ومن كتب أو أطلق */
    private static function author(): array
    {
        $user = Auth::user();
        $user = $user instanceof User && ! MessageSender::insideJob() ? $user : null;
        $stack = app()->bound(self::MACHINE) ? (array) app(self::MACHINE) : [];

        if ($stack !== []) {
            return [(string) end($stack), $user];
        }

        return $user !== null ? ['human', $user] : ['ai', null];
    }

    /** @param  array<string, mixed>  $content */
    private static function write(Model $owner, string $kind, array $content, string $source, ?User $actor): void
    {
        $type = class_basename($owner);
        $next = (int) ContentRevision::where('subject_type', $type)->where('subject_id', $owner->getKey())->where('kind', $kind)->max('version') + 1;

        ContentRevision::create([
            'subject_type' => $type,
            'subject_id' => $owner->getKey(),
            'subject_ref' => self::refOf($owner),
            'kind' => $kind,
            'version' => $next,
            'source' => $source,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->role->value,
            'ip' => $actor !== null ? request()->ip() : null,
            'content' => $content,
        ]);
    }

    /**
     * @param  Closure(string): mixed  $read
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function snapshot(Closure $read, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $out[$f] = $read($f);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $values */
    private static function hasText(array $values): bool
    {
        foreach ($values as $v) {
            if (is_array($v) ? $v !== [] : trim((string) $v) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>  $b
     */
    private static function same(?array $a, array $b): bool
    {
        return json_encode($a) === json_encode(json_decode((string) json_encode($b), true));
    }

    private static function refOf(Model $owner): ?string
    {
        foreach (['number', 'ref'] as $attr) {
            $v = $owner->getAttribute($attr);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }
}
