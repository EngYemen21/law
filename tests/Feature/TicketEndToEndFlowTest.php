<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketEndToEndFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake(); // Don't run background AI jobs asynchronously during test
        $this->seed(PermissionSeeder::class);
    }

    public function test_complete_ticket_lifecycle_end_to_end(): void
    {
        // 1. إعداد المستخدمين بالأدوار المختلفة والصلاحيات
        $client = User::factory()->create([
            'role' => Role::Client,
            'name' => 'محمد العتيبي',
            'national_id' => '1000000001',
            'phone' => '0500000001',
        ]);

        $employee = User::factory()->create([
            'role' => Role::Employee,
            'name' => 'سارة المشرفة',
            'national_id' => '1000000002',
            'phone' => '0500000002',
        ]);
        $employee->syncPermissions(Permission::all());

        $lawyer = User::factory()->create([
            'role' => Role::Lawyer,
            'name' => 'أ. خالد المالكي',
            'national_id' => '1000000003',
            'phone' => '0500000003',
        ]);
        $lawyer->syncPermissions(Permission::all());

        // 2. العميل ينشئ تذكرة جديدة مع الحقول القضائية والمرفقات (POST /tickets)
        $file = UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf');

        $response = $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'details' => 'نزاع حول توريد بضاعة بقيمة 150000 ريال وإخلال بالعقد.',
            'opponent_name' => 'شركة توريد الخليج',
            'claim_amount' => 150000,
            'court_name' => 'المحكمة التجارية بالرياض',
            'files' => [$file],
        ]);

        $response->assertRedirect();

        $ticket = Ticket::where('user_id', $client->id)->first();
        $this->assertNotNull($ticket);
        $this->assertEquals('نزاع تجاري', $ticket->type);
        // الصياغة القديمة تُحفظ باسمها المعتمد في الكتالوج، مع معرّفه
        $this->assertEquals('القضايا التجارية', $ticket->department);
        $this->assertNotNull($ticket->legal_department_id);
        $this->assertEquals('شركة توريد الخليج', $ticket->opponent_name);
        $this->assertEquals(150000, $ticket->claim_amount);
        $this->assertEquals('المحكمة التجارية بالرياض', $ticket->court_name);

        // 3. العميل يرسل رسالة في التذكرة (POST /tickets/{ticket}/messages)
        $msgRes = $this->actingAs($client)->post(route('tickets.messages.store', $ticket), [
            'body' => 'أرجو الإفادة بالرأي القانوني في أقرب وقت.',
        ]);
        $msgRes->assertSuccessful();
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'body' => 'أرجو الإفادة بالرأي القانوني في أقرب وقت.',
        ]);

        // 4. الموظف يفتح المحادثة ويرد باستخدام الرد السريع EM_QUICK (POST /employee/tickets/{ticket}/reply)
        $emReply = 'تم استلام طلبكم وجارٍ المتابعة مع المستشار المختص.';
        $empRes = $this->actingAs($employee)->post(route('employee.tickets.reply', $ticket), [
            'body' => $emReply,
        ]);
        $empRes->assertSuccessful();
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'body' => $emReply,
            'who' => 'staff',
        ]);

        // 5. الموظف يضيف ملاحظة داخلية خاصة بالفريق القانوني (POST /employee/tickets/{ticket}/note)
        $noteText = 'تنبيه: تم فحص العقد المرفق ويحتاج تدقيق الشرط الجزائي.';
        $noteRes = $this->actingAs($employee)->post(route('employee.tickets.note', $ticket), [
            'body' => $noteText,
        ]);
        $noteRes->assertSuccessful();
        $this->assertDatabaseHas('ticket_messages', [
            'ticket_id' => $ticket->id,
            'body' => $noteText,
            'who' => 'note',
        ]);

        // 6. الموظف يطلب نواقص ومستندات عبر TicketActionsPanel (POST /employee/tickets/{ticket}/request-docs)
        $reqRes = $this->actingAs($employee)->post(route('employee.tickets.reqdocs', $ticket), [
            'docs' => ['السجل التجاري', 'إشعار المطالبة السابق'],
        ]);
        $reqRes->assertSuccessful();
        $ticket->refresh();
        $this->assertEquals('بانتظار مستندات', $ticket->status);

        // 7. العميل يرفع المستندات الناقصة (POST /tickets/{ticket}/attach)
        $missingDoc = UploadedFile::fake()->create('cr.pdf', 300, 'application/pdf');
        $attachRes = $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => $missingDoc,
        ]);
        $attachRes->assertSuccessful();
        $ticket->refresh();

        // 8. الموظف يحيل التذكرة للمستشار خالد المالكي (POST /employee/transfer/{ticket})
        $transRes = $this->actingAs($employee)->post(route('employee.transfer.do', $ticket), [
            'department' => 'القسم التجاري',
            'lawyer_id' => $lawyer->id,
        ]);
        $transRes->assertRedirect();
        $ticket->refresh();
        $this->assertEquals($lawyer->id, $ticket->assigned_lawyer_id);

        // 9. اكتمال دراسة الملف وتحويل التذكرة لحالة مكتملة
        $ticket->update(['status' => 'مكتملة', 'tone' => 'b-green']);

        // 10. تحويل التذكرة إلى قضية رسمية عبر TicketActionsPanel (POST /lawyer/tickets/{ticket}/convert)
        $convertRes = $this->actingAs($lawyer)->post(route('lawyer.tickets.convert', $ticket));
        $convertRes->assertRedirect();

        $ticket->refresh();
        $case = LegalCase::where('user_id', $client->id)->first();
        $this->assertNotNull($case);
        $this->assertEquals('نزاع تجاري', $case->type);
        $this->assertEquals('بانتظار اعتماد الأتعاب', $case->status);
        $this->assertMatchesRegularExpression('/^CASE-\d{4}-\d{4}$/', $case->number);
    }
}
