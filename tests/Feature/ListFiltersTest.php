<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use App\Support\ExecFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **بحث وفلاتر قائمتَي التنفيذ و«قضاياي»** (قرار المالك 2026-09-28): المجموعة من الخادم لا شروطَ مرحلةٍ في
 * الواجهة — مجموعة ملفّ التنفيذ `ExecFlow::bucket` وأسماؤها `ExecFlow::BUCKETS`، وتبويبات قضايا المحامي
 * من `CaseJourney::adminTabs` نفسه الذي تقرؤه قضايا الإدارة.
 */
class ListFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_execution_stage_falls_in_one_bucket(): void
    {
        $this->assertSame('new', ExecFlow::bucket(0, false, false));
        $this->assertSame('new', ExecFlow::bucket(1, false, false));
        $this->assertSame('study', ExecFlow::bucket(4, false, false));
        $this->assertSame('offer', ExecFlow::bucket(6, false, false));
        $this->assertSame('active', ExecFlow::bucket(6, true, false), 'عرضٌ مسدَّد انفتح ملفّه');
        $this->assertSame('active', ExecFlow::bucket(8, true, false));
        $this->assertSame('closed', ExecFlow::bucket(3, false, true));
        $this->assertSame('closed', ExecFlow::bucket(10, true, false), 'مرحلةٌ قديمة بعد التنفيذ');
        foreach (range(0, 10) as $stage) {
            $this->assertArrayHasKey(ExecFlow::bucket($stage, false, false), ExecFlow::BUCKETS);
        }
    }

    public function test_the_exec_list_carries_bucket_and_bucket_names_from_the_server(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-LF-'.uniqid(), 'sanad' => 'شيك', 'subject' => 'تحصيل',
            'amount' => 1000, 'status' => 'قيد الدراسة', 'stage' => 2, 'tone' => 'b-blue',
        ]);

        $this->actingAs($client)->get(route('execs'))->assertOk()->assertInertia(fn ($p) => $p
            ->where('buckets', ExecFlow::BUCKETS)
            ->where('execs.0.bucket', 'study'));
    }

    public function test_lawyer_cases_get_the_same_status_tabs_as_the_admin(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'number' => 'CASE-LF-'.uniqid(),
            'type' => 'تجاري', 'status' => 'منظورة', 'tone' => 'b-cyan', 'assigned_lawyer_id' => $lawyer->id,
        ]);

        $this->actingAs($lawyer)->get(route('lawyer.cases'))->assertOk()->assertInertia(fn ($p) => $p
            ->component('lawyer/cases')->has('cases', 1)->where('tabs', CaseJourney::adminTabs()));
    }

    public function test_both_lists_search_with_the_shared_arabic_aware_matcher(): void
    {
        foreach (['js/pages/execflow.tsx', 'js/pages/lawyer/cases.tsx'] as $page) {
            $src = (string) file_get_contents(resource_path($page));
            $this->assertStringContainsString("import { matchesSearch } from '@/lib/employee-data';", $src);
            $this->assertStringContainsString('type="search"', $src);
        }
        $this->assertStringNotContainsString('execs.filter((r) => r.stage', (string) file_get_contents(resource_path('js/pages/execflow.tsx')), 'عدّادات المجموعات من `r.bucket` لا من شروط المرحلة');
    }
}
