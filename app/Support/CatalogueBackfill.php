<?php

namespace App\Support;

use App\Enums\Role;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * **ملء روابط الكتالوج للسجلّات القديمة** — تذاكر، وقضايا، واستشارات، وتخصّصات المحامين.
 *
 * يملأ المعرّف الفارغ فقط ولا يمسّ النصوص؛ تشغيله مرّتين لا يغيّر شيئاً. المطابقة **صارمة**
 * (`LegalCatalogue::resolveDepartment` بلا احتواء): ربطٌ خاطئ أسوأ من ترك السجلّ بلا معرّف،
 * لأنّ الإسناد يقرأ النصّ القديم حين لا معرّف.
 *
 * يعمل بـ`DB` لا بالنماذج: تناديه هجرة، ولا يلزم إطلاق خطّافات الحفظ لآلاف الصفوف.
 * يُعيد ملخّصاً بما رُبط وما لم يُطابَق (مع عدده) — يعرضه `catalogue:backfill --dry-run`.
 */
class CatalogueBackfill
{
    /**
     * @return array<string, array{linked: int, unresolved: array<string, int>}>
     */
    public static function run(bool $dryRun = false): array
    {
        LegalCatalogue::flush();

        return [
            'التذاكر' => self::linkDepartments('tickets', 'department', 'type', $dryRun),
            'خدمات التذاكر' => self::linkTicketServices($dryRun),
            'القضايا' => self::linkDepartments('cases', 'department', 'type', $dryRun),
            'الاستشارات' => self::linkDepartments('consults', 'specialty', 'type', $dryRun),
            'المحامون' => self::linkLawyers($dryRun),
        ];
    }

    /**
     * يربط صفوف جدولٍ بقسمها: من العمود الأساسيّ، وإلّا من العمود الاحتياطيّ (النوع يدلّ على القسم).
     *
     * @return array{linked: int, unresolved: array<string, int>}
     */
    private static function linkDepartments(string $table, string $column, string $fallback, bool $dryRun): array
    {
        $result = ['linked' => 0, 'unresolved' => []];

        $pairs = DB::table($table)->whereNull('legal_department_id')->select($column, $fallback)->distinct()->get();

        foreach ($pairs as $pair) {
            $primary = $pair->{$column};
            $secondary = $pair->{$fallback};
            $department = LegalCatalogue::resolveDepartment($primary) ?? LegalCatalogue::resolveDepartment($secondary);

            $rows = DB::table($table)->whereNull('legal_department_id');
            self::whereNullable($rows, $column, $primary);
            self::whereNullable($rows, $fallback, $secondary);

            if ($department === null) {
                $label = trim((string) $primary) !== '' ? (string) $primary : '(فارغ) '.$secondary;
                $result['unresolved'][$label] = ($result['unresolved'][$label] ?? 0) + (clone $rows)->count();

                continue;
            }

            // يُكتب الاسم المعتمد مع المعرّف (قرار المالك: الاسم الجديد في كلّ مكان) — فتبني المرشّحات
            // والتقارير خياراتها من صياغةٍ واحدة لا من «القسم التجاري» و«القضايا التجارية» معاً
            $result['linked'] += $dryRun
                ? (clone $rows)->count()
                : $rows->update(['legal_department_id' => $department->id, $column => $department->name]);
        }

        return $result;
    }

    /** @return array{linked: int, unresolved: array<string, int>} */
    private static function linkTicketServices(bool $dryRun): array
    {
        $result = ['linked' => 0, 'unresolved' => []];

        $pairs = DB::table('tickets')->whereNotNull('legal_department_id')->whereNull('legal_service_id')
            ->select('legal_department_id', 'type')->distinct()->get();

        foreach ($pairs as $pair) {
            $service = LegalCatalogue::resolveService($pair->type, (int) $pair->legal_department_id);
            $rows = DB::table('tickets')->whereNull('legal_service_id')
                ->where('legal_department_id', $pair->legal_department_id)->where('type', $pair->type);

            if ($service === null) {
                $result['unresolved'][(string) $pair->type] = ($result['unresolved'][(string) $pair->type] ?? 0) + (clone $rows)->count();

                continue;
            }

            $result['linked'] += $dryRun ? (clone $rows)->count() : $rows->update(['legal_service_id' => $service->id]);
        }

        return $result;
    }

    /**
     * «كل الأقسام» ← علامة التغطية؛ وغيره ← صفّ تخصّص، لمن لا صفوف له بعد.
     *
     * @return array{linked: int, unresolved: array<string, int>}
     */
    private static function linkLawyers(bool $dryRun): array
    {
        $result = ['linked' => 0, 'unresolved' => []];
        $now = now();

        $lawyers = DB::table('users')->where('role', Role::Lawyer->value)
            ->whereNotNull('department')->where('department', '!=', '')
            ->where('covers_all_departments', false)
            ->whereNotExists(fn (Builder $q) => $q->from('lawyer_specialties')->whereColumn('lawyer_specialties.user_id', 'users.id'))
            ->get(['id', 'department']);

        foreach ($lawyers as $lawyer) {
            if (trim($lawyer->department) === Specialties::ALL_DEPARTMENTS) {
                $dryRun || DB::table('users')->where('id', $lawyer->id)->update(['covers_all_departments' => true]);
                $result['linked']++;

                continue;
            }

            $department = LegalCatalogue::resolveDepartment($lawyer->department);
            if ($department === null) {
                $result['unresolved'][$lawyer->department] = ($result['unresolved'][$lawyer->department] ?? 0) + 1;

                continue;
            }

            $dryRun || DB::table('lawyer_specialties')->insertOrIgnore([
                'user_id' => $lawyer->id, 'legal_department_id' => $department->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $result['linked']++;
        }

        return $result;
    }

    /** شرط مساواةٍ يحترم NULL — `where(col, null)` في SQL لا يطابق شيئاً. */
    private static function whereNullable(Builder $query, string $column, mixed $value): void
    {
        $value === null ? $query->whereNull($column) : $query->where($column, $value);
    }
}
