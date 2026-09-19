<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * **هل يغطّي هذا المحامي هذا القسم؟** — المصدر الواحد لمطابقة المحامي بالقسم.
 *
 * يستعمله الإسناد التلقائيّ للتذاكر، وترتيب المتخصّصين في طلب الاستشارة، وقائمة المحامين في
 * شاشة الاستشارة. كانت كلُّها تقارن نصّ `users.department` بنصّ القسم عبر `Specialties::matches`،
 * فلا يُسنَد محامٍ لـ١٣ قسماً من ٢٩ ولا يكون للمحامي إلّا تخصّصٌ واحد.
 *
 * **ترتيب الحكم:**
 *   ١. يغطّي كلّ الأقسام (`covers_all_departments` أو نصّ «كل الأقسام» القديم) ← نعم.
 *   ٢. تخصّصاته في `lawyer_specialties`، وإن خلت فنصّ `users.department` القديم مطابَقاً بالكتالوج.
 *   ٣. قسمٌ معروف وتخصّصاتٌ معروفة ← المقارنة بالمعرّف.
 *   ٤. غير ذلك (نصوصٌ حرّة لا يعرفها الكتالوج) ← تطابق النصّين بعد توحيد الصياغة، كما كان.
 */
class LawyerSpecialties
{
    /** هل يغطّي المحامي كلّ الأقسام؟ */
    public static function coversAll(User $lawyer): bool
    {
        return (bool) $lawyer->covers_all_departments
            || trim((string) $lawyer->department) === Specialties::ALL_DEPARTMENTS;
    }

    /**
     * معرّفات أقسام المحامي — من الجدول، أو من النصّ القديم حين لا صفوف له.
     * يُفضَّل تحميل العلاقة مسبقاً (`with('specialties')`) عند المرور على محامين كثيرين.
     *
     * @return list<int>
     */
    public static function departmentIds(User $lawyer): array
    {
        $ids = $lawyer->relationLoaded('specialties')
            ? $lawyer->specialties->pluck('id')
            : $lawyer->specialties()->pluck('legal_departments.id');

        if ($ids->isNotEmpty()) {
            return array_values(array_map(fn ($id) => (int) $id, $ids->all()));
        }

        $legacy = LegalCatalogue::resolveDepartment($lawyer->department, loose: true);

        return $legacy === null ? [] : [$legacy->id];
    }

    /**
     * يكتب تخصّصات المحامي كما اختارتها الإدارة: صفوف الجدول، وعلامة التغطية العامّة، ونسخة العرض
     * في `users.department` (أوّل تخصّص أو «كل الأقسام») التي تقرؤها بطاقاتٌ كثيرة كنصّ.
     *
     * يُعيد الوصف قبل الكتابة وبعدها لسجلّ التدقيق.
     *
     * @param  array<int, int|string>  $departmentIds
     * @return array{before: string, after: string}
     */
    public static function sync(User $lawyer, array $departmentIds, bool $coversAll): array
    {
        $before = self::label($lawyer);
        $ids = $coversAll ? [] : array_values(array_unique(array_map('intval', $departmentIds)));

        DB::transaction(function () use ($lawyer, $ids, $coversAll) {
            $lawyer->specialties()->sync($ids);
            $lawyer->forceFill([
                'covers_all_departments' => $coversAll,
                'department' => $coversAll ? Specialties::ALL_DEPARTMENTS : LegalCatalogue::department($ids[0] ?? null)?->name,
            ])->save();
        });

        $lawyer->unsetRelation('specialties');

        return ['before' => $before, 'after' => self::label($lawyer)];
    }

    /** يُفرغ تخصّصات حسابٍ لم يعد محامياً — كي لا يبقى في مسبح الإسناد بتخصّصٍ قديم. */
    public static function clear(User $user): void
    {
        $user->specialties()->detach();
        $user->forceFill(['covers_all_departments' => false])->save();
        $user->unsetRelation('specialties');
    }

    /** وصفٌ مقروء لتخصّصات المحامي بترتيب الكتالوج — لبطاقة الموظّف وسجلّ التدقيق. */
    public static function label(User $lawyer): string
    {
        if (self::coversAll($lawyer)) {
            return Specialties::ALL_DEPARTMENTS;
        }

        $names = LegalCatalogue::departments(activeOnly: false)
            ->whereIn('id', self::departmentIds($lawyer))
            ->pluck('name')
            ->all();

        return $names === [] ? '—' : implode('، ', $names);
    }

    /**
     * هل يغطّي المحامي القسم؟ يُمرَّر المعرّف إن عُرف، والنصّ للسجلّات والمدخلات القديمة.
     */
    public static function covers(User $lawyer, ?int $departmentId, ?string $rawDepartment = null): bool
    {
        if (self::coversAll($lawyer)) {
            return true;
        }

        $target = $departmentId ?? LegalCatalogue::resolveDepartment($rawDepartment, loose: true)?->id;
        $own = self::departmentIds($lawyer);

        if ($target !== null && $own !== []) {
            return in_array($target, $own, true);
        }

        $a = LegalCatalogue::foldName($lawyer->department);

        return $a !== '' && $a === LegalCatalogue::foldName($rawDepartment);
    }
}
