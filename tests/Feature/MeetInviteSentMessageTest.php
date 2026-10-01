<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MeetRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **رسالة «أُرسلت الدعوة» تقول ما وقع** (قرار المالك 2026-10-01): كانت الواجهة تكتب لكلّ مُرسِل
 * «تم إرسال الدعوة وإشعارها إلى العميل» — ودعوة الموظف/المحامي لا تصل العميل قبل موافقة الإدارة.
 * الخادم وحده يعرف المسار، فيقول نصّه في `flash.success`، والواجهة لا تكتب ادّعاءً ثابتاً.
 */
class MeetInviteSentMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function payload(User $client, User $lawyer): array
    {
        return [
            'client_id' => $client->id,
            'lawyer_id' => $lawyer->id,
            'service' => 'نزاع تجاري',
            'type' => 'استشارة مرئية',
            'day' => now()->addDays(3)->format('Y-m-d'),
            'time' => '11:00',
        ];
    }

    public function test_employee_is_told_the_invitation_awaits_admin_approval(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'عميل الاختبار']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['إرسال دعوات الاجتماعات', 'جدولة المواعيد'])->get());

        $response = $this->actingAs($employee)->post(route('employee.meetreqs.store'), $this->payload($client, $lawyer));

        $ref = MeetRequest::firstOrFail()->ref;
        $response->assertRedirect()->assertSessionHas(
            'success',
            "أُرسلت الدعوة ({$ref}) إلى الإدارة العليا بانتظار موافقتها — لا تصل العميل عميل الاختبار إلا بعد الاعتماد."
        );
        $this->assertStringNotContainsString('وصل الإشعار إلى العميل', (string) session('success'));
    }

    public function test_admin_is_told_the_invitation_reached_the_client(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'عميل الاختبار']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $response = $this->actingAs($admin)->post(route('admin.meetreqs.store'), $this->payload($client, $lawyer));

        $req = MeetRequest::firstOrFail();
        $this->assertSame(MeetRequest::STAGE_CONFIRMED, $req->stage);
        $response->assertRedirect()->assertSessionHas(
            'success',
            "تم إرسال الدعوة ({$req->ref}) ونشرها — وصل الإشعار إلى العميل: عميل الاختبار"
        );
    }

    /** الواجهة لا تكتب ادّعاء الوصول للعميل — النصّ من الخادم وحده (`ServerFeedback`). */
    public function test_frontend_does_not_hardcode_the_delivery_claim(): void
    {
        $ui = (string) file_get_contents(resource_path('js/lib/meeting-ui.tsx'));

        $this->assertStringNotContainsString('تم إرسال الدعوة وإشعارها إلى العميل', $ui);
    }
}
