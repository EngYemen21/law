<?php

namespace App\Services\Ai;

use App\Models\LegalSource;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * مزامنة ملفّات `database/legal-sources/` مع جدول المصادر — **متكرّرة بلا أثر** وآمنة للنشر.
 *
 * السياسة (بالمعرّف `ref`):
 *
 * | الصفّ في الجدول            | ما يحدث                                                              |
 * |----------------------------|----------------------------------------------------------------------|
 * | غير موجود                  | يُنشأ **مسودة** — أو «معتمد» إن شهد له الملفّ (`LegalSourceBundle::vouches`) |
 * | مطابق                      | لا شيء — التشغيل الثاني لا يُنشئ ولا يغيّر                             |
 * | النصّ تغيّر والإصدار نفسه  | **خطأ يوقف المزامنة كلّها**: نصٌّ نظاميّ لا يُستبدل بصمت             |
 * | تغيّر، والصفّ معتمد بلا شهادة | **يُترك كما هو** ويُبلَّغ عنه (معلَّق)                                 |
 * | تغيّر في غير ذلك           | يُحدَّث، والحالة لا تُمسّ                                             |
 *
 * **لماذا لا تُخفَّض حالة المعتمد؟** الحالة قرار محامٍ مسجَّل في سجلّ التدقيق، والمزامنة أمرٌ
 * آليّ في النشر: لا تُلغي قراراً بشرياً. ولا تضع نصّاً لم يُراجَع تحت لافتة «معتمد» أيضاً — فالمعلَّق
 * يبقى على نصّه القديم المعتمد حتى يُوقفه محامٍ من الشاشة أو يُشحن ملفٌّ مشهودٌ له بالإصدار الجديد.
 *
 * كلّ الملفّات تُفحص قبل أيّ كتابة، والكتابة في معاملة واحدة: إمّا المزامنة كلّها أو لا شيء.
 */
final class LegalSourceSync
{
    public const CREATED = 'created';

    public const CREATED_APPROVED = 'created_approved';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const HELD = 'held';

    /** @var array<string, array<string, int>> */
    public array $counts = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $held = [];

    /**
     * @param  list<LegalSourceBundle>  $bundles
     */
    public function run(array $bundles, bool $dryRun = false): bool
    {
        $this->counts = $this->errors = $this->held = [];

        foreach ($bundles as $bundle) {
            foreach ($bundle->errors as $error) {
                $this->errors[] = "{$bundle->name()}: {$error}";
            }
        }

        // المعرّف مفتاح المزامنة: تكراره بين ملفّين يجعل أحدهما يكتب فوق الآخر في كلّ نشر
        $owner = [];
        foreach ($bundles as $bundle) {
            foreach ($bundle->rows as $row) {
                $ref = is_array($row) ? ($row['ref'] ?? null) : null;
                if (! is_string($ref)) {
                    continue;
                }
                if (isset($owner[$ref]) && $owner[$ref] !== $bundle->name()) {
                    $this->errors[] = "{$bundle->name()}: المعرّف {$ref} مكرّر في {$owner[$ref]}.";
                }
                $owner[$ref] = $bundle->name();
            }
        }

        if ($this->errors !== []) {
            return false;
        }

        $existing = LegalSource::query()->whereIn('ref', array_keys($owner))->get()->keyBy('ref');
        $plan = [];

        foreach ($bundles as $bundle) {
            $this->counts[$bundle->name()] = array_fill_keys([self::CREATED, self::CREATED_APPROVED, self::UPDATED, self::UNCHANGED, self::HELD], 0);

            foreach ($bundle->rows as $row) {
                $attributes = self::attributes($row);
                $current = $existing->get($row['ref']);
                $action = $this->decide($bundle, $row, $attributes, $current);
                if ($action === null) {
                    continue; // خطأ مسجَّل
                }
                $this->counts[$bundle->name()][$action]++;
                if (in_array($action, [self::CREATED, self::CREATED_APPROVED, self::UPDATED], true)) {
                    $plan[] = [$bundle, $row, $attributes, $current, $action];
                }
            }
        }

        if ($this->errors !== []) {
            return false;
        }

        if ($dryRun || $plan === []) {
            return true;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as [$bundle, $row, $attributes, $current, $action]) {
                if ($current === null) {
                    LegalSource::create($attributes + ($action === self::CREATED_APPROVED
                        ? ['status' => LegalSource::STATUS_APPROVED, 'legal_review_at' => $bundle->reviewed['at']]
                        : ['status' => LegalSource::STATUS_DRAFT]));

                    continue;
                }

                // الحالة والمُعتمِد لا تُمسّ؛ وحين يشهد الملفّ لنصٍّ معتمدٍ جديد يُحدَّث تاريخ مراجعته معه
                $current->update($attributes + ($current->status === LegalSource::STATUS_APPROVED && $bundle->vouches($row)
                    ? ['legal_review_at' => $bundle->reviewed['at']]
                    : []));
            }
        });

        foreach ($this->counts as $file => $c) {
            $written = $c[self::CREATED] + $c[self::CREATED_APPROVED] + $c[self::UPDATED];
            if ($written > 0) {
                Audit::log(
                    action: 'مزامنة مصادر قانونيّة',
                    description: "{$file}: أُنشئ {$c[self::CREATED]} مسودةً و{$c[self::CREATED_APPROVED]} معتمداً بشهادة الملفّ، وحُدِّث {$c[self::UPDATED]}، وعُلِّق {$c[self::HELD]}.",
                    category: 'المساعد القانوني',
                    severity: $c[self::CREATED_APPROVED] > 0 ? 'warning' : 'info',
                    auditableRef: $file,
                );
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $attributes
     */
    private function decide(LegalSourceBundle $bundle, array $row, array $attributes, ?LegalSource $current): ?string
    {
        if ($current === null) {
            return $bundle->vouches($row) ? self::CREATED_APPROVED : self::CREATED;
        }

        $stored = self::attributes(array_merge($current->only(LegalSourceBundle::FIELDS), [
            'effective_from' => $current->effective_from?->format('Y-m-d'),
            'effective_to' => $current->effective_to?->format('Y-m-d'),
        ]));

        if ($stored === $attributes) {
            return self::UNCHANGED;
        }

        if ($stored['text'] !== $attributes['text'] && $stored['version'] === $attributes['version']) {
            $this->errors[] = "{$bundle->name()}: {$row['ref']} تغيّر نصّه والإصدار «".($attributes['version'] ?? '—').'» نفسه — سجّل إصداراً جديداً قبل استبدال نصّ نظاميّ.';

            return null;
        }

        if ($current->status === LegalSource::STATUS_APPROVED && ! $bundle->vouches($row)) {
            $this->held[] = "{$row['ref']} ({$bundle->name()})";

            return self::HELD;
        }

        return self::UPDATED;
    }

    /**
     * صفّ الملفّ بالصيغة المخزَّنة — والولاية الافتراضيّة كما في `ai:import-sources`.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function attributes(array $row): array
    {
        $out = [];
        foreach (LegalSourceBundle::FIELDS as $field) {
            $value = $row[$field] ?? null;
            $out[$field] = $value === '' ? null : $value;
        }
        $out['jurisdiction'] ??= 'السعودية';

        return $out;
    }
}
