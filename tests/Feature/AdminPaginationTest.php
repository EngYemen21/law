<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ترقيم جداول الإدارة (الدفعة هـ): كانت تُجلب كاملة بـget() بلا حدّ —
 * صفر paginate في المشروع كلّه — فتتحوّل مع النموّ إلى حمولة Inertia بميغابايتات.
 * الفلاتر انتقلت للخادم كي لا تقتصر التصفية على الصفحة الحالية.
 */
class AdminPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin]);
    }

    public function test_clients_are_paginated_with_meta(): void
    {
        User::factory()->count(55)->create(['role' => Role::Client]);

        $this->actingAs($this->admin())->get(route('admin.clients'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/clients')
                ->has('clients.data', 50)
                ->where('clients.meta.total', 55)
                ->where('clients.meta.last_page', 2)
                ->where('clients.meta.current_page', 1));
    }

    public function test_second_page_returns_the_remainder(): void
    {
        User::factory()->count(55)->create(['role' => Role::Client]);

        $this->actingAs($this->admin())->get(route('admin.clients', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('clients.data', 5)->where('clients.meta.current_page', 2));
    }

    public function test_accounting_totals_cover_all_invoices_not_just_the_page(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        foreach (range(1, 55) as $i) {
            Invoice::create([
                'user_id' => $client->id, 'number' => 'INV-P-'.$i, 'description' => 'أتعاب', 'amount' => 100,
                'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'اليوم', 'paid' => false,
            ]);
        }

        $this->actingAs($this->admin())->get(route('admin.accounting'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('invoices.data', 50)
                ->where('invoices.meta.total', 55)
                // الإجماليات على كل الفواتير لا على الصفحة
                ->where('totals.issued', 5500)
                ->where('totals.unpaid', 55));
    }

    public function test_accounting_filter_is_applied_server_side(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-PAID', 'description' => 'أتعاب', 'amount' => 100,
            'status' => 'مدفوعة', 'tone' => 'b-green', 'due_label' => 'اليوم', 'paid' => true,
        ]);
        Invoice::create([
            'user_id' => $client->id, 'number' => 'INV-DUE', 'description' => 'أتعاب', 'amount' => 200,
            'status' => 'مستحقة', 'tone' => 'b-amber', 'due_label' => 'اليوم', 'paid' => false,
        ]);

        $this->actingAs($this->admin())->get(route('admin.accounting', ['filter' => 'مدفوعة']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('invoices.data', 1)
                ->where('invoices.data.0.no', 'INV-PAID')
                ->where('filter', 'مدفوعة'));
    }

    public function test_admin_tickets_are_paginated(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        foreach (range(1, 55) as $i) {
            Ticket::create([
                'user_id' => $client->id, 'number' => 'SB-P-'.$i, 'type' => 'تجاري',
                'status' => 'قيد المعالجة', 'tone' => 'b-blue',
            ]);
        }

        $this->actingAs($this->admin())->get(route('admin.tickets'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('tickets.data', 50)->where('tickets.meta.total', 55));
    }
}
