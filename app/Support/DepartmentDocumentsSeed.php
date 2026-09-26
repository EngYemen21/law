<?php

namespace App\Support;

use App\Models\LegalDepartment;
use Illuminate\Support\Facades\DB;

/**
 * **زرع قوائم مستندات الأقسام** من `database/data/legal_department_documents.php` — تناديه هجرة الزرع
 * (`2026_09_26_150200`) و`catalogue:seed-documents` (بـ`--dry-run` للمعاينة).
 *
 * القواعد (نظير `LegalCatalogueSeeder`):
 *   - يُربط كلّ نوعٍ قديم بقسمه بمطابقة الكتالوج الصارمة — ما لا يُطابَق يُبلَّغ ولا يُخمَّن له قسم.
 *   - يُكتب في قسمٍ **لا قائمة له** فقط: قائمةٌ حرّرتها الإدارة لا يُكتب فوقها، فالتكرار آمن.
 *   - نوعان في قسمٍ واحد تُدمج بنودهما بعد توحيد الصياغة؛ والبند إلزاميّ إن ألزمه أيٌّ منهما.
 *
 * والحصيلة تسمّي **الأقسام الفعّالة التي تبقى على القائمة العامّة** — ما يحتاج قرار المالك، إذ لا
 * يُختلق متطلّبٌ قانونيّ ليس في القوائم القديمة.
 */
class DepartmentDocumentsSeed
{
    /**
     * @return array{
     *     departments: list<array{department: string, sources: list<string>, action: string, documents: list<array{name: string, required: bool}>}>,
     *     unresolved: list<string>,
     *     needsOwner: list<string>
     * }
     */
    public static function run(bool $dryRun = false): array
    {
        /** @var array<string, list<array{name: string, required: bool}>> $lists */
        $lists = require database_path('data/legal_department_documents.php');

        $merged = [];      // معرّف القسم ⇒ [مفتاح موحَّد ⇒ بند]
        $sources = [];     // معرّف القسم ⇒ الأنواع القديمة التي وقعت فيه
        $unresolved = [];

        foreach ($lists as $label => $documents) {
            $department = LegalCatalogue::resolveDepartment($label);
            if ($department === null) {
                $unresolved[] = $label;

                continue;
            }

            $sources[$department->id][] = $label;
            foreach ($documents as $document) {
                $key = LegalCatalogue::foldName($document['name']);
                $existing = $merged[$department->id][$key] ?? null;
                $merged[$department->id][$key] = [
                    'name' => $existing['name'] ?? $document['name'],
                    'required' => ($existing['required'] ?? false) || $document['required'],
                ];
            }
        }

        $report = [];
        DB::transaction(function () use ($merged, $sources, $dryRun, &$report) {
            foreach ($merged as $departmentId => $documents) {
                /** @var LegalDepartment $department */
                $department = LegalCatalogue::department($departmentId);
                $documents = array_values($documents);
                // بـ`DB` لا بالنماذج: تناديه هجرة، والهجرة يجب أن تعمل ولو تغيّر النموذج لاحقاً
                $hasList = DB::table('legal_department_documents')->where('legal_department_id', $departmentId)->exists();

                if (! $hasList && ! $dryRun) {
                    $now = now();
                    DB::table('legal_department_documents')->insert(array_map(fn (array $document, int $i) => [
                        'legal_department_id' => $departmentId,
                        'name' => $document['name'],
                        'required' => $document['required'],
                        'sort_order' => ($i + 1) * 10,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $documents, array_keys($documents)));
                }

                $report[] = [
                    'department' => $department->name,
                    'sources' => $sources[$departmentId],
                    'action' => $hasList ? 'skipped' : ($dryRun ? 'would_seed' : 'seeded'),
                    'documents' => $documents,
                ];
            }
        });

        LegalCatalogue::flush();

        // قسمٌ فعّال بلا قائمة (ولن يُزرع له شيء) ⇒ على القائمة العامّة حتى يقرّر المالك قائمته
        $needsOwner = LegalCatalogue::departments()
            ->filter(fn (LegalDepartment $d) => $d->documents->isEmpty() && ! isset($merged[$d->id]))
            ->pluck('name')
            ->values()
            ->all();

        return ['departments' => $report, 'unresolved' => $unresolved, 'needsOwner' => $needsOwner];
    }
}
