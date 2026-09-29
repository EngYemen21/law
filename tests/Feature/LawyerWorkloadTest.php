<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\ConsultStatus;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\LegalCase;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **حِمل المحامي من مصدرٍ واحد** (تطوير صفحة «المحامون» 2026-09-29) — صفحتا «المحامون» و«التوزيع»
 * كانتا تحسبانه كلٌّ بطريقته: الأولى التذاكر وحدها، والثانية بقائمة حالاتٍ عربيّة مكتوبة في المتحكّم
 * تُسقط الاستشارة التي «لم يحضر» عميلها وهي مفتوحةٌ رسميّاً (`Consult::CLOSED_STATUSES`).
 */
class LawyerWorkloadTest extends TestCase
{
    use RefreshDatabase;

    private function consult(User $client, User $lawyer, string $status): Consult
    {
        return Consult::create([
            'user_id' => $client->id,
            'ref' => 'CON-'.uniqid(),
            'status' => $status,
            'type' => 'استشارة',
            'subject' => 'استشارة تجارية',
            'channel' => 'هاتفية',
            'day' => 'الأحد',
            'time' => '10ص',
            'when_label' => 'الأحد · 10ص',
            'lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
        ]);
    }

    public function test_no_show_consult_still_counts_on_the_distribute_screen(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->consult($client, $lawyer, ConsultStatus::NoShow->value);
        $this->consult($client, $lawyer, ConsultStatus::Ended->value);

        $this->actingAs($admin)->get(route('admin.distribute'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('lawyers.0.activeConsultsCount', 1));
    }

    public function test_lawyers_page_and_distribute_screen_agree_on_the_load(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);

        Ticket::create(['user_id' => $client->id, 'number' => 'SB-'.uniqid(), 'type' => 'نزاع', 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'assigned_lawyer_id' => $lawyer->id]);
        LegalCase::create(['user_id' => $client->id, 'number' => 'CAS-'.uniqid(), 'type' => 'تجاري', 'status' => 'منظورة', 'tone' => 'b-amber', 'assigned_lawyer_id' => $lawyer->id]);
        Execution::create(['user_id' => $client->id, 'number' => 'EX-'.uniqid(), 'subject' => 'سند لأمر', 'status' => 'دراسة الطلب', 'tone' => 'b-purple', 'assigned_lawyer_id' => $lawyer->id]);
        $this->consult($client, $lawyer, ConsultStatus::NoShow->value);

        // تذكرة + قضيّة×2 + تنفيذ×2 + استشارة = 6
        $this->actingAs($admin)->get(route('admin.distribute'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('lawyers.0.totalLoad', 6)->where('lawyers.0.capacityStatus', 'moderate'));

        $this->actingAs($admin)->get(route('admin.lawyers'))->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('lawyers.0.load.tickets', 1)
                ->where('lawyers.0.load.cases', 1)
                ->where('lawyers.0.load.executions', 1)
                ->where('lawyers.0.load.consults', 1)
                ->where('lawyers.0.load.total', 6)
                ->where('lawyers.0.load.capacity', 'moderate'));
    }

    public function test_lawyer_file_lists_open_work_with_links_and_matches_the_counts(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $case = LegalCase::create(['user_id' => $client->id, 'number' => 'CAS-'.uniqid(), 'type' => 'تجاري', 'status' => 'منظورة', 'tone' => 'b-amber', 'assigned_lawyer_id' => $lawyer->id]);
        $noShow = $this->consult($client, $lawyer, ConsultStatus::NoShow->value);
        $this->consult($client, $lawyer, ConsultStatus::Cancelled->value);
        Task::create(['assigned_to' => $lawyer->id, 'title' => 'مذكرة متأخرة', 'due_at' => today()->subDays(2), 'status' => 'جديدة']);
        Task::create(['assigned_to' => $lawyer->id, 'title' => 'مذكرة قادمة', 'due_at' => today()->addDays(2), 'status' => 'جديدة']);
        Task::create(['assigned_to' => $lawyer->id, 'title' => 'مذكرة منجزة', 'due_at' => today()->subDays(2), 'status' => Task::DONE]);

        $res = $this->actingAs($admin)->getJson(route('admin.lawyers.show', $lawyer))->assertOk()
            ->assertJsonPath('load.cases', 1)
            ->assertJsonPath('load.consults', 1)
            ->assertJsonPath('load.overdueTasks', 1)
            ->assertJsonPath('links.earnings', "/admin/staff/{$lawyer->id}/earnings");

        $groups = collect($res->json('groups'))->keyBy('key');
        $this->assertSame('/admin/cases/'.$case->number, $groups['cases']['items'][0]['href']);
        $this->assertSame([$noShow->ref], array_column($groups['consults']['items'], 'ref'));
        $this->assertSame(['مذكرة متأخرة'], array_column($groups['overdueTasks']['items'], 'title'));

        $this->actingAs($admin)->get(route('admin.lawyers'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('lawyers.0.load.overdueTasks', 1));
    }

    public function test_lawyer_file_is_for_lawyers_and_for_admins_only(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($admin)->getJson(route('admin.lawyers.show', $employee))->assertNotFound();
        $this->actingAs($lawyer)->getJson(route('admin.lawyers.show', $lawyer))->assertForbidden();
    }
}
