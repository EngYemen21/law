<?php

namespace App\Support;

use App\Models\LegalCatalogueAlias;
use App\Models\LegalDepartment;
use App\Models\LegalService;
use Illuminate\Support\Collection;

/**
 * **كتالوج الأقسام والخدمات القانونيّة — نقطة القراءة الوحيدة.**
 *
 * كانت القائمة مكتوبةً في خمسة أماكن (LEGAL_CATS · SVC · DEPTS · Specialties::ALL ·
 * AiPromptRegistry::DEPARTMENTS)، والمطابقة احتوائيّةٌ تُخطئ. هنا تُقرأ الجداول مرّةً لكلّ طلب،
 * وكلُّ من يحتاج قسماً أو خدمة — نموذج التذكرة، والإسناد، والذكاء، والتقارير — يسأل هذا الصنف.
 *
 * **ترتيب المطابقة (`resolveDepartment`):**
 *   ١. اسم القسم بعد توحيد الصياغة.
 *   ٢. اسمٌ بديل (legal_catalogue_aliases) — قسماً أو خدمةً تُرجع قسمها.
 *   ٣. اسم خدمةٍ فريد بين الأقسام — نوع التذكرة القديم يدلّ على قسمه.
 *   ٤. (`$loose` فقط) احتواءٌ — أطول اسمٍ ظاهرٍ في النصّ، وإلّا أقصر اسمٍ يحتوي النصّ؛
 *      لنصوصٍ حرّة قديمة لا اسم بديل لها، ولا يُستعمل حيث تلزم الدقّة (التحقّق من المدخلات).
 *
 * **الذاكرة لكلّ طلب لا لكلّ عمليّة** (نمط SettingsRegistry): `scoped` يُنسى بين وظيفتين
 * في الطابور وبين اختبارين. والحفظ يمسحها عبر خطّافات في AppServiceProvider.
 */
class LegalCatalogue
{
    /** رمز القسم العامّ الذي دُمجت فيه الاستشارات والترافع والصياغة. */
    public const GENERAL_CODE = 'general';

    private const CACHE = 'support.legal-catalogue.snapshot';

    /** أقصر نصٍّ يُقبل في المطابقة الاحتوائيّة — «عام» و«قسم» لا تدلّ على قسم. */
    private const LOOSE_MIN_LENGTH = 4;

    /**
     * صيغةٌ موحّدة للمقارنة: توحيد الهمزات والتاء المربوطة (SearchText::fold)، وحذف بادئة
     * «قسم/القسم»، وضغط المسافات. تُحسب بها `alias_folded` فتتطابق الكتابة والقراءة حرفيّاً.
     */
    public static function foldName(?string $raw): string
    {
        $folded = SearchText::fold((string) $raw);
        $folded = preg_replace('/^(ال)?قسم\s+/u', '', $folded) ?? $folded;

        return trim(preg_replace('/\s+/u', ' ', $folded) ?? $folded);
    }

    /**
     * الأقسام مرتّبةً مع خدماتها.
     *
     * @return Collection<int, LegalDepartment>
     */
    public static function departments(bool $activeOnly = true): Collection
    {
        $all = self::snapshot()['departments'];

        return $activeOnly ? $all->filter->isActive()->values() : $all;
    }

    /** قسمٌ بمعرّفه أو رمزه (موقوفاً أو فعّالاً). */
    public static function department(int|string|null $idOrCode): ?LegalDepartment
    {
        if ($idOrCode === null || $idOrCode === '') {
            return null;
        }

        $snap = self::snapshot();

        return is_int($idOrCode) || ctype_digit((string) $idOrCode)
            ? $snap['byId'][(int) $idOrCode] ?? null
            : $snap['byCode'][$idOrCode] ?? null;
    }

    /** خدمةٌ بمعرّفها (موقوفةً أو فعّالة). */
    public static function service(?int $id): ?LegalService
    {
        return $id === null ? null : self::snapshot()['services'][$id] ?? null;
    }

    /**
     * القسم الذي تدلّ عليه قيمةٌ وصلت في طلب: معرّفٌ رقميّ، أو اسمٌ/اسمٌ بديل (مطابقة صارمة).
     * مصدرٌ واحد لقاعدة التحقّق والمتحكّم معاً — فلا يقبل التحقّقُ قيمةً يقرؤها المتحكّم بغير معناها.
     */
    public static function fromInput(mixed $value): ?LegalDepartment
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return self::department((int) $value);
        }

        return is_string($value) ? self::resolveDepartment($value) : null;
    }

    /**
     * القسم الذي **يحمل هذا الاسم** — اسمه أو اسمٌ بديل (له أو لإحدى خدماته)، موقوفاً أو فعّالاً،
     * بلا مطابقة أسماء الخدمات ولا احتواء. لحارس «الاسم غير مكرّر» في شاشة الإدارة.
     */
    public static function departmentNamed(?string $raw): ?LegalDepartment
    {
        $target = self::snapshot()['names'][self::foldName($raw)] ?? null;

        return $target === null ? null : self::snapshot()['byId'][$target['department']];
    }

    /**
     * هل النصّ **اسمُ قسمٍ فعّال أو اسمٌ بديل له** — بلا مطابقة خدمةٍ ولا احتواء؟
     * لحكمٍ دقيق على مخرجات الذكاء: «قسمٌ من القائمة» لا «نصٌّ يشبه قسماً».
     */
    public static function isDepartmentName(?string $raw): bool
    {
        $target = self::snapshot()['names'][self::foldName($raw)] ?? null;

        return $target !== null
            && $target['service'] === null
            && self::snapshot()['byId'][$target['department']]->isActive();
    }

    /**
     * اسم القسم وكلّ أسمائه البديلة كما كُتبت — لمطابقة بياناتٍ نصّيّة قديمة بقسمٍ واحد
     * (مثل مجال المصادر القانونيّة) بدل صياغةٍ واحدة منها.
     *
     * @return list<string>
     */
    public static function namesFor(LegalDepartment $department): array
    {
        return array_values(array_unique([$department->name, ...(self::snapshot()['aliasesByDepartment'][$department->id] ?? [])]));
    }

    /** القسم العامّ — افتراض ما لا قسم له. */
    public static function general(): ?LegalDepartment
    {
        return self::department(self::GENERAL_CODE);
    }

    /** القسم الذي يدلّ عليه نصٌّ ما (اسم قسم، أو اسم بديل، أو اسم خدمة). */
    public static function resolveDepartment(?string $raw, bool $loose = false): ?LegalDepartment
    {
        $key = self::foldName($raw);
        if ($key === '') {
            return null;
        }

        $snap = self::snapshot();

        if (isset($snap['names'][$key])) {
            return $snap['byId'][$snap['names'][$key]['department']];
        }

        $serviceIds = $snap['serviceNames'][$key] ?? [];
        $departmentIds = array_unique(array_map(fn (int $id) => $snap['services'][$id]->legal_department_id, $serviceIds));
        if (count($departmentIds) === 1) {
            return $snap['byId'][reset($departmentIds)];
        }

        return $loose ? self::looseDepartment($key) : null;
    }

    /**
     * الخدمة التي يدلّ عليها نصٌّ ما — داخل قسمٍ محدّد إن مُرِّر، وإلّا بشرط ألّا تلتبس بين قسمين.
     */
    public static function resolveService(?string $raw, ?int $departmentId = null): ?LegalService
    {
        $key = self::foldName($raw);
        if ($key === '') {
            return null;
        }

        $snap = self::snapshot();
        $inDepartment = fn (int $id) => $departmentId === null || $snap['services'][$id]->legal_department_id === $departmentId;

        $candidates = array_values(array_filter($snap['serviceNames'][$key] ?? [], $inDepartment));

        $alias = $snap['names'][$key] ?? null;
        if ($candidates === [] && $alias !== null && $alias['service'] !== null && $inDepartment($alias['service'])) {
            $candidates = [$alias['service']];
        }

        return count($candidates) === 1 ? $snap['services'][$candidates[0]] : null;
    }

    /** هل الخدمة تتبع هذا القسم (وفعّالةٌ هي وقسمها إن طُلب)؟ — حارس نموذج التذكرة. */
    public static function serviceBelongs(int $serviceId, int $departmentId, bool $activeOnly = true): bool
    {
        $snap = self::snapshot();
        $service = $snap['services'][$serviceId] ?? null;
        $department = $snap['byId'][$departmentId] ?? null;

        if ($service === null || $department === null || $service->legal_department_id !== $departmentId) {
            return false;
        }

        return ! $activeOnly || ($service->isActive() && $department->isActive());
    }

    /**
     * ما تعرضه قوائم الاختيار: الفعّال وحده، مرتّباً.
     *
     * @return array<int, array{id: int, name: string, services: array<int, array{id: int, name: string}>}>
     */
    public static function forSelect(): array
    {
        return self::departments()->map(fn (LegalDepartment $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'services' => $d->services->filter->isActive()
                ->map(fn (LegalService $s) => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all(),
        ])->values()->all();
    }

    /** يُنسي الكتالوج المحفوظ للطلب — يناديه الحافظ كي يقرأ الطلبُ نفسُه ما كتب. */
    public static function flush(): void
    {
        app()->forgetInstance(self::CACHE);
    }

    /**
     * مطابقةٌ احتوائيّة في اتّجاهين، ولكلٍّ قاعدته:
     *   ١. اسمٌ داخل النصّ («قضايا الشركات» يحوي «الشركات») ← **أطول** اسمٍ محتوى: الأدقّ.
     *   ٢. النصّ داخل اسم («تجاري» في «التجاري») ← **أقصر** اسمٍ يحتويه: الأقرب.
     *      أطولُها كان يختار «الأوراق التجارية والمطالبات المالية» لكلمة «تجاري».
     * الأوّل مقدَّم: اسمٌ كاملٌ ظاهر في النصّ أوثق من نصٍّ قصيرٍ داخل اسم.
     */
    private static function looseDepartment(string $key): ?LegalDepartment
    {
        $snap = self::snapshot();
        $contained = null;
        $containedLength = 0;
        $containing = null;
        $containingLength = PHP_INT_MAX;
        $keyLongEnough = mb_strlen($key) >= self::LOOSE_MIN_LENGTH;

        foreach ($snap['names'] as $name => $target) {
            $length = mb_strlen($name);
            if ($length < self::LOOSE_MIN_LENGTH) {
                continue;
            }

            if ($length > $containedLength && mb_strpos($key, $name) !== false) {
                $contained = $target['department'];
                $containedLength = $length;
            } elseif ($keyLongEnough && $length < $containingLength && mb_strpos($name, $key) !== false) {
                $containing = $target['department'];
                $containingLength = $length;
            }
        }

        $best = $contained ?? $containing;

        return $best === null ? null : $snap['byId'][$best];
    }

    /**
     * لقطة الكتالوج للطلب: الأقسام بخدماتها، وخرائط المطابقة الموحّدة الصياغة.
     *
     * @return array{
     *     departments: Collection<int, LegalDepartment>,
     *     byId: array<int, LegalDepartment>,
     *     byCode: array<string, LegalDepartment>,
     *     services: array<int, LegalService>,
     *     names: array<string, array{department: int, service: int|null}>,
     *     serviceNames: array<string, list<int>>,
     *     aliasesByDepartment: array<int, list<string>>
     * }
     */
    private static function snapshot(): array
    {
        $app = app();

        if (! $app->bound(self::CACHE)) {
            $app->scoped(self::CACHE, fn () => self::build());
        }

        return $app->make(self::CACHE);
    }

    /** @return array<string, mixed> */
    private static function build(): array
    {
        $departments = LegalDepartment::query()->ordered()->with('services')->get();

        $byId = $departments->keyBy('id')->all();
        $byCode = $departments->keyBy('code')->all();
        $services = [];
        $names = [];
        $serviceNames = [];

        foreach ($departments as $department) {
            $names[self::foldName($department->name)] = ['department' => $department->id, 'service' => null];

            foreach ($department->services as $service) {
                $services[$service->id] = $service;
                $serviceNames[self::foldName($service->name)][] = $service->id;
            }
        }

        // الأسماء البديلة لا تطغى على اسم قسمٍ فعليّ؛ وأسماء القسم نفسه (لا خدماته) تُحفظ كما كُتبت لـ`namesFor`
        $aliasesByDepartment = [];
        foreach (LegalCatalogueAlias::query()->get(['alias', 'alias_folded', 'legal_department_id', 'legal_service_id']) as $alias) {
            $names[$alias->alias_folded] ??= ['department' => $alias->legal_department_id, 'service' => $alias->legal_service_id];

            if ($alias->legal_service_id === null) {
                $aliasesByDepartment[$alias->legal_department_id][] = $alias->alias;
            }
        }

        return compact('departments', 'byId', 'byCode', 'services', 'names', 'serviceNames', 'aliasesByDepartment');
    }
}
