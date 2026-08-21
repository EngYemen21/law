<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ExecService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أعطال اللوحات (الدفعة أ) — كل اختبار يمثّل زرًّا أو حسابًا كان مكسورًا فعلاً.
 */
class DashboardDefectsTest extends TestCase
{
    use RefreshDatabase;

    // ── مسار جلسات استشارات المحامي: المتحكّم كان يصيّر lawyer/consults بلا مسار ولا صفحة ──

    public function test_lawyer_consults_route_renders_its_page(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-A-1', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'session' => 'بانتظار الجلسة', 'status' => 'جديدة',
        ]);

        $this->actingAs($lawyer)->get(route('lawyer.consults'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('lawyer/consults')->has('consults', 1));
    }

    // ── الضريبة: كانت مصلّبة 0.15 في القضايا والتنفيذ بينما الاستشارات تقرأ الإعداد ──

    public function test_case_fee_vat_follows_admin_setting(): void
    {
        Setting::put('vat_rate', 5);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-VAT-1', 'type' => 'تجاري',
            'status' => 'بانتظار اعتماد الأتعاب', 'tone' => 'b-amber', 'fee_status' => 'none',
        ]);

        $this->actingAs($admin)->post(route('admin.cases.fee', $case), ['fee' => 10000])->assertRedirect();

        // الضريبة تظهر في نصّ الفاتورة وفي مبلغها (لا عمود vat على القضية)
        $this->assertStringContainsString('ضريبة 500', (string) $case->fresh()->invoice_text);
        $this->assertSame(10500, (int) Invoice::where('case_id', $case->id)->firstOrFail()->amount); // 5% لا 15%
    }

    public function test_execution_fee_vat_follows_admin_setting(): void
    {
        Setting::put('vat_rate', 5);
        $client = User::factory()->create(['role' => Role::Client]);
        $execution = Execution::create([
            'user_id' => $client->id, 'number' => 'EXE-VAT-1', 'subject' => 'تنفيذ',
            'status' => 'جديد', 'tone' => 'b-blue', 'last_action' => 'فتح', 'stage' => 3,
        ]);

        ExecService::setFee($execution, 10000, '30 يوم', 'تحويل');

        $this->assertSame(500, (int) $execution->fresh()->vat);
    }

    // ── حساب الإدارة المُنشأ من شاشة الموظفين كان يختفي من الجدول ──

    public function test_admin_accounts_appear_in_staff_list(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        User::factory()->create(['role' => Role::Employee]);
        User::factory()->create(['role' => Role::Lawyer]);

        $this->actingAs($admin)->get(route('admin.staff'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/staff')->has('staff', 3));
    }

    public function test_staff_card_exposes_role_key_for_admin_guarding(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->assertSame('admin', $admin->staffCard()['roleKey']);
    }

    // ── تقرير الاستشارة: كان مسجّلاً تحت role:client فقط، فزرّ المكتب توست بلا مسار ──

    public function test_office_roles_can_open_consult_report(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-A-2', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'session' => 'منتهية', 'status' => 'مكتملة', 'summary' => 'ملخص الجلسة',
        ]);

        $this->actingAs($lawyer)->get(route('consults.report.plain', $consult))->assertOk();
        $this->actingAs($client)->get(route('consults.report.plain', $consult))->assertOk();
    }

    // ── بطاقة الموعد: رابط تقويم بتواريخ حقيقية ورابط جلسة حقيقي ──

    public function test_appointment_card_carries_real_calendar_and_session_links(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-A-1', 'type' => 'تجاري',
            'status' => 'قيد المعالجة', 'tone' => 'b-blue',
        ]);

        $card = Appointment::create([
            'user_id' => $client->id, 'ticket_id' => $ticket->id, 'ext_id' => 'AP-A-1',
            'type' => 'استشارة مرئية', 'ico' => 'video', 'lawyer' => 'أ. سارة',
            'day' => 'الأحد', 'time' => '10:00', 'starts_at' => now()->addDay(),
            'duration_min' => 45, 'place' => 'اجتماع إلكتروني', 'status' => 'مؤكد',
            'tone' => 'b-green', 'when_kind' => 'up',
        ])->toCard();

        $this->assertStringContainsString('dates=', $card['gcal']);
        $this->assertStringNotContainsString('salaselbabel.net/APT-', (string) $card['joinLink']);
    }
}
