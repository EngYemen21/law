<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerName;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientConciergeDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_client_dashboard_renders_360_concierge_data(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'سعد التميمي']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. فهد السبيعي', 'department' => 'قضايا الشركات']);

        // 1. تذكرة بانتظار مستندات لتوليد تنبيه ذكي
        Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-901',
            'type' => 'تأسيس شركات',
            'subject' => 'عقد اتفاقية شركاء',
            'department' => 'قضايا الشركات',
            'status' => 'بانتظار مستندات',
            'tone' => 'b-amber',
            'assigned_lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        // 2. قضية جارية
        $case = LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CASE-2026-901',
            'type' => 'تجاري',
            'department' => 'قضايا الشركات',
            'status' => 'منظورة',
            'tone' => 'b-cyan',
            'assigned_lawyer_id' => $lawyer->id,
            'assigned_lawyer' => $lawyer->name,
        ]);

        CaseHearing::create([
            'case_id' => $case->id,
            'title' => 'جلسة المرافعة',
            'day' => 'الاثنين',
            'court' => 'المحكمة التجارية بالرياض',
            'status' => 'مجدولة',
            'starts_at' => now()->addDays(2),
        ]);

        // 3. موعد استشارة اليوم
        $appt = Appointment::create([
            'user_id' => $client->id,
            'ext_id' => 'APT-2026-901',
            'type' => 'مرئية',
            'ico' => 'video',
            'lawyer' => $lawyer->name,
            'lawyer_id' => $lawyer->id,
            'day' => 'اليوم',
            'time' => '11:00 ص',
            'starts_at' => now()->setHour(11),
            'place' => 'اجتماع مرئي',
            'status' => 'مؤكد',
            'tone' => 'b-green',
            'when_kind' => 'today',
        ]);

        Consult::create([
            'appointment_id' => $appt->id,
            'user_id' => $client->id,
            'ref' => 'CN-2026-901',
            'subject' => 'استشارة تجارية',
            'type' => 'تجاري',
            'channel' => 'مرئية',
            'lawyer' => $lawyer->name,
            'assigned_lawyer_id' => $lawyer->id,
            'status' => 'مؤكد',
            'session' => 'بانتظار الجلسة',
            'meet_link' => 'https://meet.google.com/test',
            'link_released_at' => now()->subMinute(),
        ]);

        // 4. فاتورة
        Invoice::create([
            'user_id' => $client->id,
            'number' => 'INV-2026-901',
            'description' => 'أتعاب استشارة',
            'amount' => 1500,
            'status' => 'مستحقة',
            'tone' => 'b-amber',
            'due_label' => 'اليوم',
            'due_at' => now()->subDay(), // متأخرة
            'paid' => false,
        ]);

        // 5. ملف تنفيذ
        Execution::create([
            'user_id' => $client->id,
            'number' => 'EXEC-2026-901',
            'subject' => 'تنفيذ سند لأمر',
            'status' => 'قيد التنفيذ',
            'tone' => 'b-cyan',
            'amount' => 500000,
            'stage' => 3,
        ]);

        $response = $this->actingAs($client)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('counts')
            ->where('counts.openTickets', 1)
            ->where('counts.activeCases', 1)
            ->where('counts.upAppts', 1)
            ->where('counts.dueInv', 1)
            ->where('counts.overdueInv', 1)
            ->where('counts.myExec', 1)
            ->has('actionAlerts')
            ->has('upcomingAppts', 1)
            ->has('activeCases', 1)
            ->has('activeTickets', 1)
            ->has('activeExecutions', 1)
            ->has('assignedAdvisor')
            // لوحةُ العميل تعرض الاسم المختصر لا الكامل (قرار المالك 2026-09-11) —
            // وكانت هذه البطاقة آخرَ موضعٍ يفلت منه، فيصل «أ. فهد السبيعي» كاملاً.
            ->where('assignedAdvisor.name', LawyerName::short($lawyer->name))
        );
    }
}
