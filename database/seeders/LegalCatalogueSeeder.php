<?php

namespace Database\Seeders;

use App\Support\LegalCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * **يزرع كتالوج الأقسام والخدمات المعتمد** من `database/data/legal_catalogue.php`، وأقسام الموظّفين الإداريّة.
 *
 * **إضافةٌ لما ليس موجوداً — لا كتابة فوق شيء.** بعد الزرع يملك الكتالوجَ شاشةُ الإدارة: قسمٌ
 * موجود برمزه يُترك بخدماته وأسمائه البديلة كما هو، فإعادة الزرع لا تُرجع اسماً غيّرته الإدارة ولا
 * تُحيي خدمةً أوقفتها. والقسم الجديد (رمزٌ غير موجود) يُزرع كاملاً.
 *
 * يُكتب بـ`DB` لا بالنماذج: تناديه هجرة، والهجرة يجب أن تعمل ولو تغيّرت النماذج لاحقاً.
 */
class LegalCatalogueSeeder extends Seeder
{
    /**
     * أقسام الموظّفين الإداريّة — القيمتان الموجودتان فعلاً في قائمة الطاقم السابقة (DEPTS)،
     * بلا أقسامٍ مختلقة. تُزرع فقط والجدول فارغ.
     */
    public const STAFF_DEPARTMENTS = ['خدمة العملاء', 'الإدارة المالية'];

    public function run(): void
    {
        /** @var list<array{code: string, name: string, sort_order: int, requires_specific_authority: bool, aliases: list<string>, services: list<array{name: string, sort_order: int, aliases?: list<string>}>}> $catalogue */
        $catalogue = require database_path('data/legal_catalogue.php');

        DB::transaction(function () use ($catalogue) {
            foreach ($catalogue as $department) {
                $this->seedDepartment($department);
            }

            $this->seedStaffDepartments();
        });

        LegalCatalogue::flush();
    }

    /** @param array{code: string, name: string, sort_order: int, requires_specific_authority: bool, aliases: list<string>, services: list<array{name: string, sort_order: int, aliases?: list<string>}>} $department */
    private function seedDepartment(array $department): void
    {
        if (DB::table('legal_departments')->where('code', $department['code'])->exists()) {
            return;
        }

        $now = now();
        $departmentId = DB::table('legal_departments')->insertGetId([
            'code' => $department['code'],
            'name' => $department['name'],
            'sort_order' => $department['sort_order'],
            'status' => 'active',
            'requires_specific_authority' => $department['requires_specific_authority'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->seedAliases($department['aliases'], $departmentId, null);

        foreach ($department['services'] as $service) {
            $serviceId = DB::table('legal_services')->insertGetId([
                'legal_department_id' => $departmentId,
                'name' => $service['name'],
                'sort_order' => $service['sort_order'],
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->seedAliases($service['aliases'] ?? [], $departmentId, $serviceId);
        }
    }

    /**
     * `insertOrIgnore` على `alias_folded` الفريد: اسمٌ بديل سبق زرعه (أو أضافته إعادة تسمية) لا يُكرَّر.
     *
     * @param  list<string>  $aliases
     */
    private function seedAliases(array $aliases, int $departmentId, ?int $serviceId): void
    {
        $now = now();

        $rows = array_map(fn (string $alias) => [
            'alias' => $alias,
            'alias_folded' => LegalCatalogue::foldName($alias),
            'legal_department_id' => $departmentId,
            'legal_service_id' => $serviceId,
            'source' => 'seed',
            'created_at' => $now,
            'updated_at' => $now,
        ], $aliases);

        if ($rows !== []) {
            DB::table('legal_catalogue_aliases')->insertOrIgnore($rows);
        }
    }

    private function seedStaffDepartments(): void
    {
        if (DB::table('staff_departments')->exists()) {
            return;
        }

        $now = now();
        DB::table('staff_departments')->insert(array_map(fn (string $name, int $i) => [
            'name' => $name,
            'sort_order' => ($i + 1) * 10,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], self::STAFF_DEPARTMENTS, array_keys(self::STAFF_DEPARTMENTS)));
    }
}
