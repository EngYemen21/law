<?php

namespace Tests\Feature;

use App\Models\LegalCatalogueAlias;
use App\Models\LegalDepartment;
use App\Models\LegalService;
use App\Models\StaffDepartment;
use App\Services\Ai\AiPromptRegistry;
use App\Support\LegalCatalogue;
use Database\Seeders\LegalCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كتالوج الأقسام والخدمات: الزرع المعتمد، والمطابقة، والذاكرة، وحرّاس ملف البيانات.
 *
 * الأعداد تُشتقّ من `database/data/legal_catalogue.php` لا تُكتب أرقاماً — فتعديل الكتالوج
 * المعتمد لا يكسر الاختبار، وانحراف الزرع عنه يكسره.
 */
class LegalCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function data(): array
    {
        return require database_path('data/legal_catalogue.php');
    }

    // ── الزرع ────────────────────────────────────────────

    public function test_migration_seeds_the_approved_catalogue_exactly(): void
    {
        $data = $this->data();

        $this->assertSame(count($data), LegalDepartment::count());
        $this->assertSame(array_sum(array_map(fn ($d) => count($d['services']), $data)), LegalService::count());
        $this->assertSame(
            array_column($data, 'name'),
            LegalDepartment::query()->ordered()->pluck('name')->all(),
            'ترتيب الأقسام أو أسماؤها انحرفت عن الملف المعتمد.'
        );
        $this->assertSame(LegalCatalogueSeeder::STAFF_DEPARTMENTS, StaffDepartment::orderBy('sort_order')->pluck('name')->all());
    }

    public function test_reseeding_never_overwrites_what_management_changed(): void
    {
        $labor = LegalDepartment::where('code', 'labor')->firstOrFail();
        $labor->update(['name' => 'قضايا العمل']);
        $service = $labor->services()->firstOrFail();
        $service->update(['status' => 'suspended']);
        StaffDepartment::where('name', 'الإدارة المالية')->update(['name' => 'المالية']);
        $counts = [LegalDepartment::count(), LegalService::count(), LegalCatalogueAlias::count(), StaffDepartment::count()];

        app(LegalCatalogueSeeder::class)->run();

        $this->assertSame('قضايا العمل', $labor->fresh()->name);
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame($counts, [LegalDepartment::count(), LegalService::count(), LegalCatalogueAlias::count(), StaffDepartment::count()]);
    }

    // ── المطابقة ─────────────────────────────────────────

    public function test_fold_name_unifies_hamza_ta_marbuta_and_the_department_prefix(): void
    {
        $this->assertSame(LegalCatalogue::foldName('القضايا التجارية'), LegalCatalogue::foldName('  قسم القضايا التجاريه '));
        $this->assertSame(LegalCatalogue::foldName('الأحوال الشخصية'), LegalCatalogue::foldName('القسم الاحوال الشخصيه'));
    }

    public function test_department_resolves_by_name_alias_and_legacy_ticket_type(): void
    {
        $code = fn (?string $raw) => LegalCatalogue::resolveDepartment($raw)?->code;

        $this->assertSame('labor', $code('القضايا العمالية'));
        $this->assertSame('commercial', $code('القسم التجاري'), 'صياغة صفحة الاستشارة القديمة.');
        $this->assertSame('banking', $code('البنوك والتمويل'), 'تخصّص الخادم القديم.');
        $this->assertSame('criminal', $code('القضايا الجزائية والجنائية'), 'الاسم قبل إعادة التسمية.');
        $this->assertSame('general', $code('الترافع والتمثيل القانوني'), 'قسمٌ دُمج في العامّ.');
        $this->assertSame('commercial', $code('نزاع تجاري'), 'نوع تذكرة من صفحة الاستشارة القديمة.');
        $this->assertSame('enforcement', $code('الإفصاح عن أموال المنفذ ضده'), 'اسم خدمةٍ فريد يدلّ على قسمه.');
        $this->assertNull($code(''));
        $this->assertNull($code('كل الأقسام'), '«كل الأقسام» علامة تغطيةٍ لا قسم.');
    }

    /** 🔴 المطابقة الاحتوائيّة القديمة ردّت هذا القسم إلى «القضايا التجارية» لأنّ اسمه يحوي «التجاري». */
    public function test_competition_is_no_longer_swallowed_by_commercial(): void
    {
        $this->assertSame('competition', LegalCatalogue::resolveDepartment('المنافسة والامتثال التجاري')?->code);
        $this->assertSame('commercial_papers', LegalCatalogue::resolveDepartment('الأوراق التجارية والمطالبات المالية')?->code);
    }

    public function test_an_old_name_renamed_in_two_departments_matches_nothing(): void
    {
        $this->assertNull(LegalCatalogue::resolveDepartment('المطالبة بالتعويض'));
        $this->assertNull(LegalCatalogue::resolveService('المطالبة بالتعويض'));
    }

    public function test_loose_matching_is_opt_in_for_free_legacy_text(): void
    {
        $this->assertNull(LegalCatalogue::resolveDepartment('قضايا الشركات'));
        $this->assertSame('corporate', LegalCatalogue::resolveDepartment('قضايا الشركات', loose: true)?->code);
        $this->assertNull(LegalCatalogue::resolveDepartment('عام', loose: true), 'نصٌّ قصير لا يدلّ على قسم.');
    }

    public function test_service_resolution_respects_department_and_ambiguity(): void
    {
        $medical = LegalCatalogue::department('medical');
        $traffic = LegalCatalogue::department('traffic');

        $this->assertSame('التعويض عن الخطأ الطبي', LegalCatalogue::resolveService('التعويض عن الخطأ الطبي', $medical->id)?->name);
        $this->assertNull(LegalCatalogue::resolveService('التعويض عن الخطأ الطبي', $traffic->id));
        $this->assertSame('منازعات الشيكات', LegalCatalogue::resolveService('الشيكات')?->name, 'الاسم قبل إعادة التسمية.');
    }

    // ── الحرّاس ──────────────────────────────────────────

    public function test_service_belongs_only_to_its_active_department(): void
    {
        $labor = LegalCatalogue::department('labor');
        $realEstate = LegalCatalogue::department('real_estate');
        $service = $labor->services->first();

        $this->assertTrue(LegalCatalogue::serviceBelongs($service->id, $labor->id));
        $this->assertFalse(LegalCatalogue::serviceBelongs($service->id, $realEstate->id));

        LegalService::whereKey($service->id)->firstOrFail()->update(['status' => 'suspended']);

        $this->assertFalse(LegalCatalogue::serviceBelongs($service->id, $labor->id));
        $this->assertTrue(LegalCatalogue::serviceBelongs($service->id, $labor->id, activeOnly: false));
    }

    public function test_select_options_hide_suspended_departments_and_services(): void
    {
        $labor = LegalDepartment::where('code', 'labor')->firstOrFail();
        $insurance = LegalDepartment::where('code', 'insurance')->firstOrFail();
        $hidden = $insurance->services()->firstOrFail();

        $labor->update(['status' => 'suspended']);
        $hidden->update(['status' => 'suspended']);

        $options = collect(LegalCatalogue::forSelect());

        $this->assertFalse($options->contains('id', $labor->id));
        $this->assertFalse(collect($options->firstWhere('id', $insurance->id)['services'])->contains('id', $hidden->id));
    }

    public function test_saving_flushes_the_request_snapshot(): void
    {
        $this->assertSame('labor', LegalCatalogue::resolveDepartment('القضايا العمالية')?->code);

        LegalDepartment::where('code', 'labor')->firstOrFail()->update(['name' => 'قضايا العمل']);

        $this->assertSame('labor', LegalCatalogue::resolveDepartment('قضايا العمل')?->code, 'لقطة الطلب لم تُمسح بعد الحفظ.');
    }

    /** تعليمتا الفرز وتصنيف القضيّة تعرضان على النموذج الأقسامَ الفعّالة من الكتالوج — لا الموقوفة. */
    public function test_ai_prompts_list_the_active_catalogue_departments(): void
    {
        LegalDepartment::where('code', 'media')->firstOrFail()->update(['status' => 'suspended']);

        $triage = AiPromptRegistry::ticketTriageSystem();

        foreach (LegalCatalogue::departments() as $department) {
            $this->assertStringContainsString($department->name, $triage);
        }
        $this->assertStringNotContainsString('الإعلام والنشر', $triage, 'قسمٌ موقوف لا يُعرض على النموذج.');
        $this->assertStringContainsString(AiPromptRegistry::departments(), AiPromptRegistry::caseClassifySystem());
    }

    public function test_general_department_exists(): void
    {
        $this->assertSame(LegalCatalogue::GENERAL_CODE, LegalCatalogue::general()?->code);
    }

    /** حرّاس الملف المعتمد نفسه — تعديلٌ لاحق عليه لا يُعيد التكرار أو القنوات. */
    public function test_approved_data_file_has_no_duplicates_channels_or_merged_departments(): void
    {
        $data = $this->data();
        $departmentNames = array_map(fn ($d) => LegalCatalogue::foldName($d['name']), $data);

        foreach ($data as $department) {
            $folded = array_map(fn ($s) => LegalCatalogue::foldName($s['name']), $department['services']);
            $this->assertSame(count($folded), count(array_unique($folded)), "تكرارٌ داخل {$department['name']}.");
        }

        $services = array_merge(...array_map(fn ($d) => array_column($d['services'], 'name'), $data));
        foreach (['استشارة مكتوبة', 'استشارة هاتفية', 'استشارة مرئية', 'استشارة حضورية'] as $channel) {
            $this->assertNotContains($channel, $services, 'قناة الاستشارة ليست خدمة.');
        }

        foreach (['الاستشارات القانونية', 'الترافع والتمثيل القانوني', 'الصياغة والأعمال القانونية المساندة'] as $merged) {
            $this->assertNotContains(LegalCatalogue::foldName($merged), $departmentNames, "«{$merged}» دُمج في القسم العامّ.");
        }
    }
}
