<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketSummary;
use App\Models\User;
use App\Services\LegalAiService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * طلب النواقص من الموظف: يبني الرسالة خادميّاً (تهريب أسماء المستندات فقط — لا كود مدموج)،
 * يبثّ الحالة «بانتظار مستندات»، ويشعر العميل؛ ورفع النواقص يعيد الإحالة للمستشار ويشعره.
 */
class EmployeeRequestDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function openTicket(User $client): Ticket
    {
        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري', 'department' => 'القسم التجاري', 'details' => 'أطالب بمستحقاتي بموجب العقد.',
        ]);

        return Ticket::latest('id')->firstOrFail();
    }

    private function employeeFor(Ticket $ticket): User
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['الرد على العملاء'])->get());

        return $employee;
    }

    public function test_request_docs_broadcasts_status_and_renders_escaped_chips(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->openTicket($client);
        $employee = $this->employeeFor($ticket);

        $this->actingAs($employee)->post(route('employee.tickets.reqdocs', $ticket), [
            'docs' => ['صورة الهوية الوطنية', 'عقد الإيجار'],
        ])->assertNoContent();

        $ticket->refresh();
        $this->assertSame('بانتظار مستندات', $ticket->status); // بثّ حالة فعلي (لا انتقال /status مرفوض)

        $msg = $ticket->messages()->where('role', 'نواقص')->latest('id')->firstOrFail();
        // HTML مُصيَّر فعلاً (لا كود مهرَّب يظهر للعميل) + أسماء المستندات ظاهرة داخل الشرائح
        $this->assertStringContainsString('<div class="doc-list">', $msg->body);
        $this->assertStringContainsString('صورة الهوية الوطنية', $msg->body);
        $this->assertStringNotContainsString('&lt;div', $msg->body);

        // إشعار العميل بالنواقص (يُبثّ لحظياً)
        $this->assertDatabaseHas('user_notifications', ['user_id' => $client->id, 'icon' => 'upload']);
    }

    public function test_request_docs_escapes_html_in_document_names(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->openTicket($client);
        $employee = $this->employeeFor($ticket);

        $this->actingAs($employee)->post(route('employee.tickets.reqdocs', $ticket), [
            'docs' => ['<script>alert(1)</script>'],
        ])->assertNoContent();

        $msg = $ticket->messages()->where('role', 'نواقص')->latest('id')->firstOrFail();
        // اسم المستند مهرَّب (لا حقن)، بينما غلاف القائمة يبقى وسماً حقيقياً
        $this->assertStringContainsString('&lt;script&gt;', $msg->body);
        $this->assertStringNotContainsString('<script>', $msg->body);
        $this->assertStringContainsString('<span class="doc-chip">', $msg->body);
    }

    public function test_rejects_request_docs_on_completed_ticket(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = $this->openTicket($client);
        $ticket->update(['status' => 'مكتملة']);
        $employee = $this->employeeFor($ticket);

        // مودال النواقص ينادي بـaxios (Accept: application/json) فيصل الرفض 422 بجسم أخطاء
        $this->actingAs($employee)->postJson(route('employee.tickets.reqdocs', $ticket), [
            'docs' => ['أي مستند'],
        ])->assertStatus(422);

        $this->assertSame('مكتملة', $ticket->fresh()->status);
    }

    public function test_uploading_missing_docs_refers_and_notifies_lawyer(): void
    {
        config(['services.ai_agent.enabled' => true]);
        $client = User::factory()->create(['role' => Role::Client]);
        // القسم مطابق لقسم التذكرة: الإسناد الأوّل يشترط التخصّص الآن، وبلا متخصّص
        // تبقى التذكرة بلا محامٍ ويُصعَّد الأمر (LawyerAssignmentPolicyTest يغطّي ذلك).
        // موضوع هذا الاختبار إحالة المستندات لا سياسة الإسناد.
        User::factory()->create(['role' => Role::Lawyer, 'department' => 'القسم التجاري']);
        $ticket = $this->openTicket($client); // الوكيل يضعها في «بانتظار مستندات»
        $this->partialMock(LegalAiService::class, function ($mock) {
            $mock->shouldReceive('analyzeDocument')->andReturn([
                'related' => true, 'doc_type' => 'عقد', 'summary' => 'عقد يخص النزاع.', 'reason' => 'مرتبط بالموضوع.',
            ]);
        });

        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        ])->assertNoContent();

        $ticket->refresh();
        // إعادة الإحالة للمستشار مع تحديث الملخّص
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->status);
        $this->assertSame('awaiting_lawyer', TicketSummary::where('ticket_id', $ticket->id)->firstOrFail()->status);

        // إشعار المستشار المسند بمراجعة الملخّص (البق: لم يكن يُشعَر سابقاً)
        $this->assertNotNull($ticket->assigned_lawyer_id);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $ticket->assigned_lawyer_id]);
    }
}
