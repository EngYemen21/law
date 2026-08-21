<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Document;
use App\Models\Execution;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminClientManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    private function client(array $attributes = []): User
    {
        static $counter = 1000;
        $counter++;

        return User::factory()->create(array_merge([
            'role' => Role::Client,
            'national_id' => '112233'.$counter,
            'phone' => '055'.str_pad((string) $counter, 7, '0', STR_PAD_LEFT),
            'status' => 'active',
        ], $attributes));
    }

    public function test_admin_can_view_clients_index_page(): void
    {
        $client = $this->client(['name' => 'محمد عبدالله']);

        $res = $this->actingAs($this->admin())->get(route('admin.clients'));
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('admin/clients')
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'محمد عبدالله')
            ->where('clients.data.0.id', $client->id)
        );
    }

    public function test_admin_can_search_clients(): void
    {
        $this->client(['name' => 'أحمد علي']);
        $this->client(['name' => 'خالد حسن']);

        $res = $this->actingAs($this->admin())->get(route('admin.clients', ['q' => 'أحمد']));
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'أحمد علي')
        );
    }

    public function test_admin_can_filter_clients_by_status(): void
    {
        $activeClient = $this->client(['name' => 'عميل نشط', 'status' => 'active']);
        $suspendedClient = $this->client(['name' => 'عميل موقوف', 'status' => 'suspended']);

        // فلترة النشطين فقط
        $res = $this->actingAs($this->admin())->get(route('admin.clients', ['status' => 'active']));
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'عميل نشط')
        );

        // فلترة الموقوفين فقط
        $res = $this->actingAs($this->admin())->get(route('admin.clients', ['status' => 'suspended']));
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'عميل موقوف')
        );
    }

    public function test_admin_can_filter_clients_by_activity_and_date(): void
    {
        $clientWithCase = $this->client(['name' => 'عميل لديه قضية']);
        $clientWithoutActivity = $this->client(['name' => 'عميل جديد']);

        LegalCase::create([
            'user_id' => $clientWithCase->id,
            'number' => 'CASE-2026-9999',
            'type' => 'قضية تجارية',
            'status' => 'منظورة',
            'tone' => 't-blue',
        ]);

        // فلترة العملاء الذين لديهم قضايا
        $res = $this->actingAs($this->admin())->get(route('admin.clients', ['activity' => 'has_cases']));
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'عميل لديه قضية')
        );

        // فلترة العملاء الجدد بلا نشاط
        $res = $this->actingAs($this->admin())->get(route('admin.clients', ['activity' => 'inactive']));
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'عميل جديد')
        );
    }

    public function test_admin_can_view_client_detail_page_with_full_activity(): void
    {
        $client = $this->client(['name' => 'سارة المنصور']);

        // إنشاء تذكرة وقضية واستشارة وتنفيذ وفاتورة ومستند
        Ticket::create([
            'user_id' => $client->id,
            'number' => 'SB-2026-1001',
            'type' => 'استشارة تجارية',
            'subject' => 'استشارة نزاع تجاري',
            'status' => 'قيد التحليل',
            'tone' => 't-blue',
        ]);

        LegalCase::create([
            'user_id' => $client->id,
            'number' => 'CASE-2026-1001',
            'type' => 'قضية عمالية',
            'status' => 'منظورة',
            'tone' => 't-blue',
            'fee' => 15000,
            'fee_status' => 'pending_payment',
        ]);

        Consult::create([
            'user_id' => $client->id,
            'ref' => 'CN-2026-1001',
            'subject' => 'جلسة مرئية استشارية',
            'channel' => 'مرئية',
            'lawyer' => 'المحامي الأول',
            'status' => 'موعد مؤكد',
            'session' => 'بانتظار الجلسة',
            'total' => 500,
        ]);

        Execution::create([
            'user_id' => $client->id,
            'number' => 'EXE-2026-1001',
            'subject' => 'تنفيذ سند لأمر',
            'sanad' => 'سند لأمر',
            'stage' => 3,
            'status' => 'دراسة السند',
            'tone' => 't-blue',
            'amount' => 50000,
        ]);

        Invoice::create([
            'user_id' => $client->id,
            'number' => 'INV-2026-1001',
            'description' => 'أتعاب قضية عمالية',
            'amount' => 15000,
            'due_label' => 'مستحقة فوراً',
            'paid' => false,
            'status' => 'مستحقة',
            'tone' => 'b-amber',
        ]);

        Document::create([
            'user_id' => $client->id,
            'name' => 'عقد_تأسيس.pdf',
            'direction' => 'up',
            'meta' => 'PDF · 1.5MB',
        ]);

        $res = $this->actingAs($this->admin())->get(route('admin.clients.show', $client));
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('admin/client-detail')
            ->where('client.name', 'سارة المنصور')
            ->where('client.id', $client->id)
            ->where('stats.totalTickets', 1)
            ->where('stats.totalCases', 1)
            ->where('stats.totalConsults', 1)
            ->where('stats.totalExecutions', 1)
            ->where('stats.totalInvoiced', 15000)
            ->has('tickets', 1)
            ->has('cases', 1)
            ->has('consults', 1)
            ->has('executions', 1)
            ->has('invoices', 1)
            ->has('documents', 1)
        );
    }

    public function test_admin_can_update_client_data(): void
    {
        $client = $this->client([
            'name' => 'عبدالعزيز السعد',
            'email' => 'abdulaziz@test.com',
            'phone' => '0559998877',
            'national_id' => '1020304050',
            'status' => 'active',
        ]);

        $res = $this->actingAs($this->admin())->put(route('admin.clients.update', $client), [
            'name' => 'عبدالعزيز السعد المحدّث',
            'email' => 'abdulaziz.new@test.com',
            'phone' => '0551112233',
            'national_id' => '1020304099',
            'status' => 'active',
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('success');

        $client->refresh();
        $this->assertSame('عبدالعزيز السعد المحدّث', $client->name);
        $this->assertSame('abdulaziz.new@test.com', $client->email);
        $this->assertSame('0551112233', $client->phone);
        $this->assertSame('1020304099', $client->national_id);
    }

    public function test_admin_can_toggle_client_status(): void
    {
        $client = $this->client(['status' => 'active']);

        $res = $this->actingAs($this->admin())->post(route('admin.clients.toggle', $client));
        $res->assertRedirect();

        $client->refresh();
        $this->assertSame('suspended', $client->status);
        $this->assertFalse($client->isActive());

        // تفعيل الحساب مرة أخرى
        $this->actingAs($this->admin())->post(route('admin.clients.toggle', $client));
        $client->refresh();
        $this->assertSame('active', $client->status);
        $this->assertTrue($client->isActive());
    }

    public function test_non_admin_cannot_access_client_management(): void
    {
        $client = $this->client();
        $otherClient = $this->client();

        // العميل لا يستطيع فتح لوحة الإدارة ويعاد توجيهه للوحة العميل
        $this->actingAs($client)->get(route('admin.clients'))->assertRedirect($client->role->home());
        $this->actingAs($client)->get(route('admin.clients.show', $otherClient))->assertRedirect($client->role->home());
        $this->actingAs($client)->put(route('admin.clients.update', $otherClient), [
            'name' => 'تعديل غير مصرح',
            'email' => 'hacked@test.com',
            'phone' => '0500000000',
            'national_id' => '1000000000',
            'status' => 'active',
        ])->assertRedirect($client->role->home());
    }
}
