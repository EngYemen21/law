<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use App\Support\ChannelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **مصدرٌ واحد لحالات القضيّة — الدفعة ٣ من إصلاح مراحلها (2026-09-11).**
 *
 * كانت تبويبات العميل والإدارة قوائمَ باليد تعدّ حالاتٍ لا يكتبها أيّ مسار، فمؤشّر «قيد
 * الترافع» صفرٌ أبداً، وقضيّةٌ مؤرشفة أو صدر حكمها لا تظهر إلا في «الكل».
 */
class CaseStatusGroupsTest extends TestCase
{
    use RefreshDatabase;

    /** كلّ حالةٍ في تبويبٍ واحد بالضبط — لا ثقب ولا ازدواج. */
    private function assertPartition(array $tabs, string $who): void
    {
        $all = array_keys(CaseJourney::STATUSES);
        $flat = array_merge(...array_values($tabs));

        $this->assertEqualsCanonicalizing($all, $flat, "{$who}: التبويبات لا تغطّي الحالات السبع بالضبط");
        $this->assertSame(count($flat), count(array_unique($flat)), "{$who}: حالةٌ في تبويبين");
    }

    public function test_client_and_admin_tabs_partition_the_seven_statuses(): void
    {
        $this->assertPartition(CaseJourney::clientTabs(), 'العميل');
        $this->assertPartition(CaseJourney::adminTabs(), 'الإدارة');
    }

    public function test_client_counts_follow_the_catalogue(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        foreach (array_keys(CaseJourney::STATUSES) as $i => $status) {
            LegalCase::create([
                'user_id' => $client->id, 'number' => "CASE-GRP-{$i}", 'type' => 'نزاع',
                'status' => $status, 'tone' => CaseJourney::toneFor($status),
            ]);
        }

        $this->actingAs($client)->get('/cases')->assertInertia(fn ($p) => $p
            ->where('counts.active', 4)       // قيد التحضير · بانتظار القيد · منظورة · صدر الحكم
            ->where('counts.pendingFees', 2)  // الحالتان بالحالة لا بـ fee_status
            ->where('counts.completed', 2)    // مغلقة · مؤرشفة
            ->where('counts.total', count(CaseJourney::STATUSES))
            ->where('tabs', CaseJourney::clientTabs()));
    }

    public function test_the_admin_kpis_count_real_statuses(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        foreach (['قيد التحضير', 'منظورة'] as $i => $status) {
            LegalCase::create(['user_id' => $client->id, 'number' => "CASE-ADM-{$i}", 'type' => 'نزاع', 'status' => $status, 'tone' => 'b-blue']);
        }

        $this->actingAs($admin)->get('/admin/cases')->assertInertia(fn ($p) => $p
            ->where('kpis.active', 2)
            ->where('tabs', CaseJourney::adminTabs()));
    }

    /** ولا شاشةَ تعدّ حالةً لا يكتبها مسار. */
    public function test_no_case_screen_counts_an_unwritten_status(): void
    {
        // حرفيّاتٌ في الكود لا ذكرٌ في تعليق — التعليقات تشرح العطل بأسماء الحالات الميتة نفسها
        $dead = ["'قيد الترافع'", "'جلسة قادمة'", "'تحت الدراسة'", "'محكومة'", "'قيد النظر'", "'جلسات جارية'", "'مرافعة'"];
        foreach ([
            app_path('Http/Controllers/CaseController.php'),
            app_path('Http/Controllers/Admin/CaseController.php'),
            resource_path('js/pages/cases.tsx'),
            resource_path('js/pages/admin/cases.tsx'),
        ] as $file) {
            $src = (string) file_get_contents($file);
            foreach ($dead as $d) {
                $this->assertStringNotContainsString($d, $src, basename($file).": «{$d}» حالةٌ لا يكتبها مسار");
            }
        }

        // لوحة الإدارة: «المغلقة» كانت تعدّ «مكتملة» (حالة تذكرة) وتُسقط «مؤرشفة». و`CLOSED_TICKETS`
        // في الملفّ نفسه صحيحةٌ لأنها للتذاكر — فالفحص على سطر القضايا وحده.
        $dash = (string) file_get_contents(app_path('Services/AdminDashboardService.php'));
        $this->assertStringNotContainsString("IN ('مكتملة', 'مغلقة') THEN 1 END) as closed_cases", $dash);
        // والعدّ اليوم من النطاق الواحد (`LegalCase::active` ← `CaseJourney::CLOSED`) لا من SQL مكتوب هنا
        $this->assertStringContainsString('LegalCase::active()->count()', $dash);
        $this->assertStringContainsString("'closed_cases' => \$totalCases - \$activeCases", $dash);
    }

    /** خريطة المراحل في الواجهة تطابق الخادم — تبقى في الواجهة لأنّ الحالة تتحدّث بالبثّ. */
    public function test_the_frontend_stage_map_mirrors_the_server(): void
    {
        $src = (string) file_get_contents(resource_path('js/lib/case-ui.tsx'));
        $body = substr($src, strpos($src, 'export function caseStage'), 600);

        preg_match_all("/case '([^']+)':\\s*(?:return (\\d+);)?/u", $body, $m, PREG_SET_ORDER);
        $map = [];
        $pending = [];
        foreach ($m as $hit) {
            $pending[] = $hit[1];
            if (isset($hit[2]) && $hit[2] !== '') {
                foreach ($pending as $status) {
                    $map[$status] = (int) $hit[2];
                }
                $pending = [];
            }
        }

        $server = array_map(fn ($s) => $s['at'], CaseJourney::STATUSES);
        ksort($map);
        ksort($server);
        $this->assertSame($server, $map, 'caseStage في الواجهة انحرف عن CaseJourney::STATUSES');
    }

    // ═════ ٣.٣ تصويبات ═════

    public function test_the_employee_court_column_comes_from_the_hearing(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'assigned_lawyer_id' => $lawyer->id, 'number' => 'CASE-CRT-1',
            'type' => 'نزاع', 'status' => 'منظورة', 'tone' => 'b-blue', 'pleading_status' => 'approved',
        ]);
        $this->actingAs($lawyer)->post(route('lawyer.cases.hearings.add', $case), [
            'title' => 'الجلسة الأولى', 'day' => now()->addWeek()->format('Y-m-d'), 'time' => '11:00', 'court' => 'الدائرة التجارية',
        ])->assertRedirect();

        $this->actingAs($employee)->get(route('employee.cases'))
            ->assertInertia(fn ($p) => $p->where('cases.0.court', 'الدائرة التجارية'));
    }

    public function test_the_lawyer_list_includes_cases_detached_from_their_ticket(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'assigned_lawyer_id' => $lawyer->id,
            'ticket_id' => null, 'number' => 'CASE-NOTK-1', 'type' => 'نزاع', 'status' => 'منظورة', 'tone' => 'b-blue',
        ]);

        $this->actingAs($lawyer)->get(route('lawyer.cases'))->assertInertia(fn ($p) => $p->has('cases', 1));
    }

    // ═════ ٣.٥ قنوات البثّ ═════

    public function test_an_employee_needs_the_page_permission_to_join_a_case_channel(): void
    {
        Permission::findOrCreate('إدارة القضايا والأتعاب', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $case = LegalCase::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'number' => 'CASE-CH-1',
            'type' => 'نزاع', 'status' => 'منظورة', 'tone' => 'b-blue',
        ]);

        $without = User::factory()->create(['role' => Role::Employee]);
        $without->syncPermissions([]);
        $with = User::factory()->create(['role' => Role::Employee]);
        $with->syncPermissions(['إدارة القضايا والأتعاب']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(ChannelAccess::staffCanSee($without, $case), 'موظّفٌ بلا صلاحيّة القضايا لا يسمع بثّها');
        $this->assertTrue(ChannelAccess::staffCanSee($with, $case));
        $this->assertTrue(ChannelAccess::staffCanSee(User::factory()->create(['role' => Role::Admin]), $case));
    }
}
