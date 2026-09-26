<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\HearingStatus;
use App\Enums\Role;
use App\Models\CaseHearing;
use App\Models\LegalCase;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminCourtHearingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_unauthenticated_cannot_access_court_hearings(): void
    {
        $this->get('/admin/hearings')
            ->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_court_hearings(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $this->actingAs($client)
            ->get('/admin/hearings')
            ->assertRedirect();

        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->actingAs($lawyer)
            ->get('/admin/hearings')
            ->assertRedirect();
    }

    public function test_admin_can_view_court_hearings_index(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'status' => 'active']);
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'فهد الأحمدي']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'المحامي سلطان الشمري', 'status' => 'active']);

        $case = LegalCase::create([
            'user_id' => $client->id,
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
            'number' => 'CASE-2026-9901',
            'title' => 'دعوى مطالبة مالية وتعويض تجاري',
            'type' => 'تجاري',
            'status' => 'منظورة',
            'tone' => 'b-blue',
            'court' => 'المحكمة التجارية بالرياض',
            'circuit' => 'الدائرة الرابعة',
            'opponent_name' => 'شركة المقاولات المتحدة',
            'update_text' => '—',
        ]);

        $hearing = CaseHearing::create([
            'case_id' => $case->id,
            'title' => 'جلسة المرافعة وتقديم البينات',
            'day' => now()->addDays(3)->toDateString(),
            'time' => '10:30',
            'starts_at' => now()->addDays(3)->setTime(10, 30),
            'court' => 'المحكمة التجارية بالرياض',
            'status' => HearingStatus::Scheduled->value,
        ]);

        $this->actingAs($admin)
            ->get('/admin/hearings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/hearings')
                ->has('hearings.data', 1)
                ->where('hearings.data.0.title', 'جلسة المرافعة وتقديم البينات')
                ->where('hearings.data.0.case.no', 'CASE-2026-9901')
                ->where('hearings.data.0.court', 'المحكمة التجارية بالرياض')
                ->has('kpis')
                ->where('kpis.total', 1)
                ->where('kpis.upcoming', 1)
                ->has('options.courts')
                ->has('options.lawyers')
            );
    }

    public function test_admin_can_filter_hearings_by_status(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'status' => 'active']);
        $client = User::factory()->create(['role' => Role::Client]);

        $case = LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CASE-2026-8801',
            'title' => 'قضية عمالية',
            'type' => 'عمالي',
            'status' => 'منظورة',
            'tone' => 'b-blue',
            'update_text' => '—',
        ]);

        // جلسة قادمة
        CaseHearing::create([
            'case_id' => $case->id,
            'title' => 'جلسة قادمة مجدولة',
            'day' => now()->addDays(5)->toDateString(),
            'starts_at' => now()->addDays(5),
            'status' => HearingStatus::Scheduled->value,
        ]);

        // جلسة منعقدة
        CaseHearing::create([
            'case_id' => $case->id,
            'title' => 'جلسة منعقدة تم ضبطها',
            'day' => now()->subDays(2)->toDateString(),
            'starts_at' => now()->subDays(2),
            'status' => HearingStatus::Held->value,
            'outcome' => 'تم سماع أقوال المدعي وتأجيل الجلسة للبينة',
        ]);

        // جلسة فائتة بانتظار تدوين النتيجة
        CaseHearing::create([
            'case_id' => $case->id,
            'title' => 'جلسة فائتة بلا نتيجة',
            'day' => now()->subDay()->toDateString(),
            'starts_at' => now()->subDay(),
            'status' => HearingStatus::Scheduled->value, // مجدولة وتاريخها ماضٍ
        ]);

        // فلترة القادمة
        $this->actingAs($admin)
            ->get('/admin/hearings?status=upcoming')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('hearings.data', 1)
                ->where('hearings.data.0.title', 'جلسة قادمة مجدولة')
            );

        // فلترة المنعقدة
        $this->actingAs($admin)
            ->get('/admin/hearings?status=held')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('hearings.data', 1)
                ->where('hearings.data.0.title', 'جلسة منعقدة تم ضبطها')
            );

        // فلترة الفائتة بانتظار النتيجة
        $this->actingAs($admin)
            ->get('/admin/hearings?status=lapsed')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('hearings.data', 1)
                ->where('hearings.data.0.title', 'جلسة فائتة بلا نتيجة')
                ->where('hearings.data.0.lapsed', true)
            );
    }

    public function test_admin_can_search_hearings(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'status' => 'active']);
        $client1 = User::factory()->create(['role' => Role::Client, 'name' => 'عبدالله الرويلي']);
        $client2 = User::factory()->create(['role' => Role::Client, 'name' => 'محمد المنصور']);

        $case1 = LegalCase::create([
            'user_id' => $client1->id,
            'number' => 'CASE-2026-1111',
            'type' => 'عقاري',
            'status' => 'منظورة',
            'tone' => 'b-blue',
            'department' => 'قسم العقارات',
            'update_text' => 'نزاع عقاري في الخبر',
        ]);

        $case2 = LegalCase::create([
            'user_id' => $client2->id,
            'number' => 'CASE-2026-2222',
            'type' => 'تجاري',
            'status' => 'منظورة',
            'tone' => 'b-blue',
            'department' => 'قسم الشركات',
            'update_text' => 'تحكيم تجاري دولي',
        ]);

        CaseHearing::create([
            'case_id' => $case1->id,
            'title' => 'جلسة المعاينة العقارية',
            'day' => now()->addDays(2)->toDateString(),
            'status' => HearingStatus::Scheduled->value,
            'starts_at' => now()->addDays(2),
        ]);

        CaseHearing::create([
            'case_id' => $case2->id,
            'title' => 'جلسة تعيين هيئة التحكيم',
            'day' => now()->addDays(4)->toDateString(),
            'status' => HearingStatus::Scheduled->value,
            'starts_at' => now()->addDays(4),
        ]);

        // بحث برقم القضية
        $this->actingAs($admin)
            ->get('/admin/hearings?q=CASE-2026-1111')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('hearings.data', 1)
                ->where('hearings.data.0.title', 'جلسة المعاينة العقارية')
            );

        // بحث بموضوع الجلسة
        $this->actingAs($admin)
            ->get('/admin/hearings?q=التحكيم')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('hearings.data', 1)
                ->where('hearings.data.0.title', 'جلسة تعيين هيئة التحكيم')
            );
    }

    public function test_admin_can_export_hearings_to_csv(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin, 'status' => 'active']);
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'سارة المطيري']);

        $case = LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CASE-2026-EXPORT',
            'title' => 'دعوى تجارية للتصدير',
            'type' => 'تجاري',
            'status' => 'منظورة',
            'tone' => 'b-blue',
            'court' => 'المحكمة العامة بالدمام',
            'update_text' => '—',
        ]);

        CaseHearing::create([
            'case_id' => $case->id,
            'title' => 'جلسة النطق بالحكم',
            'day' => '2026-10-15',
            'time' => '09:00',
            'starts_at' => now()->addDays(10),
            'court' => 'المحكمة العامة بالدمام',
            'status' => HearingStatus::Scheduled->value,
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/hearings/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('court-hearings-', (string) $response->headers->get('Content-Disposition'));

        // التحقق من محتوى التدفق
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('معرّف الجلسة', $content);
        $this->assertStringContainsString('CASE-2026-EXPORT', $content);
        $this->assertStringContainsString('جلسة النطق بالحكم', $content);
        $this->assertStringContainsString('المحكمة العامة بالدمام', $content);
    }
}
