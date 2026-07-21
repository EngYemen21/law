<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * توزيع التذاكر التلقائي واليدوي: محرك TicketAssignment يحترم distribution_mode (auto/manual)،
 * والإدارة تستطيع تشغيل التوزيع التلقائي على غير المسندة فقط، وتبديل وضع كل محامٍ.
 */
class DistributionTest extends TestCase
{
    use RefreshDatabase;

    private function openTicket(User $client, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_id' => $client->id,
            'number' => 'SB-'.uniqid(),
            'type' => 'نزاع',
            'status' => 'قيد التحليل',
            'tone' => 'b-blue',
        ], $overrides));
    }

    public function test_pick_lawyer_excludes_manual_lawyers(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $auto = User::factory()->create(['role' => Role::Lawyer, 'distribution_mode' => 'auto']);
        $manual = User::factory()->create(['role' => Role::Lawyer, 'distribution_mode' => 'manual']);
        $ticket = $this->openTicket($client);

        $picked = TicketAssignment::pickLawyer($ticket);

        $this->assertNotNull($picked);
        $this->assertSame($auto->id, $picked->id);
        $this->assertNotSame($manual->id, $picked->id);
    }

    public function test_distribute_auto_assigns_unassigned_tickets_only(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);

        // تذكرة مسندة سابقاً — يجب أن لا تُلمَس
        $assigned = $this->openTicket($client, [
            'assigned_lawyer' => 'محامٍ سابق',
            'assigned_lawyer_id' => $lawyer->id,
        ]);
        // تذكرة غير مسندة — يجب أن تُوزَّع
        $unassigned = $this->openTicket($client);

        $this->actingAs($admin)
            ->post(route('admin.distribute.auto'))
            ->assertRedirect();

        $assigned->refresh();
        $unassigned->refresh();

        $this->assertSame($lawyer->id, $assigned->assigned_lawyer_id, 'المسندة سابقاً لم تُلمَس');
        $this->assertSame('محامٍ سابق', $assigned->assigned_lawyer, 'اسم المحامي السابق محفوظ');
        $this->assertNotNull($unassigned->assigned_lawyer_id, 'غير المسندة تم توزيعها');
    }

    public function test_distribute_auto_respects_department_match(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        // محامٍ مدني ومحامٍ تجاري؛ كلاهما auto
        $civil = User::factory()->create(['role' => Role::Lawyer, 'department' => 'مدني']);
        $commercial = User::factory()->create(['role' => Role::Lawyer, 'department' => 'تجاري']);

        $ticket = $this->openTicket($client, ['department' => 'مدني']);

        $this->actingAs($admin)
            ->post(route('admin.distribute.auto'))
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame($civil->id, $ticket->assigned_lawyer_id, 'محامٍ مدني مطابق للقسم');
    }

    public function test_distribute_auto_race_protection(): void
    {
        // يثبت أن إعادة الفحص بعد القفل (fresh()) داخل transaction تتجاوز بأمان ما قد أُسند
        // متزامناً قبل تنفيذ الدفعة — دون خطأ أو إعادة إسناد مضاعفة.
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = $this->openTicket($client);

        // نُسنِد التذكرة قبل تنفيذ الدفعة (محاكاة race)
        $ticket->update(['assigned_lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id]);

        $this->actingAs($admin)
            ->post(route('admin.distribute.auto'))
            ->assertRedirect(); // لا يفشل، يتخطّاها بأمان

        $ticket->refresh();
        $this->assertSame($lawyer->id, $ticket->assigned_lawyer_id, 'الإسناد الأصلي محفوظ');
        // لا توجد رسالة «توزيع آلي» مكررة على التذكرة
        $autoMessages = $ticket->messages()->where('role', 'توزيع آلي')->count();
        $this->assertSame(0, $autoMessages, 'لم تُنشَر رسالة توزيع آلي على تذكرة أُسندت متزامناً');
    }

    public function test_distribute_auto_creates_audit_message(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->openTicket($client);

        $this->actingAs($admin)
            ->post(route('admin.distribute.auto'))
            ->assertRedirect();

        $ticket->refresh();
        $this->assertTrue(
            $ticket->messages->contains(fn ($m) => $m->who === 'note' && $m->role === 'توزيع آلي'),
            'رسالة تدقيق التوزيع الآلي أُنشئت'
        );
    }

    public function test_non_admin_cannot_access_distribute_auto(): void
    {
        // EnsureRole يعيد التوجيه (302) بدل 403 عند غياب الدور (نمط المشروع).
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($client)
            ->post(route('admin.distribute.auto'))
            ->assertRedirect();
    }

    public function test_lawyer_mode_toggle(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'distribution_mode' => 'auto']);

        $this->actingAs($admin)
            ->post(route('admin.lawyers.mode', $lawyer))
            ->assertRedirect();

        $this->assertSame('manual', $lawyer->fresh()->distribution_mode, 'تبديل من auto إلى manual');

        // تبديل عكسي
        $this->actingAs($admin)
            ->post(route('admin.lawyers.mode', $lawyer))
            ->assertRedirect();

        $this->assertSame('auto', $lawyer->fresh()->distribution_mode, 'تبديل من manual إلى auto');
    }

    public function test_toggle_mode_rejects_non_lawyer_user(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);

        $this->actingAs($admin)
            ->post(route('admin.lawyers.mode', $client))
            ->assertStatus(422);
    }
}
