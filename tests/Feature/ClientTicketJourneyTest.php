<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\LawyerAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تحقّق شامل من رحلة فتح التذكرة للعميل من البداية للنهاية:
 * فتح → قائمة → محادثة → رسالة + ردّ → إرفاق → بوابة المستندات → رحلة المعالجة السبعية.
 */
class ClientTicketJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        return User::factory()->create(['role' => Role::Client]);
    }

    private function employee(): User
    {
        return User::factory()->create(['role' => Role::Employee]);
    }

    public function test_client_opens_ticket_and_receives_acknowledgement(): void
    {
        $client = $this->client();

        $res = $this->actingAs($client)->post('/tickets', [
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'details' => 'لدي خلاف حول عقد توريد.',
        ]);

        $ticket = Ticket::firstOrFail();
        $res->assertRedirect(route('tickets.show', $ticket));

        $this->assertSame($client->id, $ticket->user_id);
        $this->assertSame('نزاع تجاري', $ticket->type);
        $this->assertSame('قيد الدراسة', $ticket->status);
        $this->assertMatchesRegularExpression('/^SB-\d{4}-\d{4}$/', $ticket->number);

        // رسالتان: رسالة العميل + إيصال الاستلام من الفريق
        $this->assertCount(2, $ticket->messages);
        $this->assertSame('client', $ticket->messages[0]->who);
        $this->assertSame('ai', $ticket->messages[1]->who);
    }

    public function test_ticket_appears_in_client_list_and_chat(): void
    {
        $client = $this->client();
        $this->actingAs($client)->post('/tickets', ['type' => 'استشارة عقود']);
        $ticket = Ticket::firstOrFail();

        $this->actingAs($client)->get('/tickets')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('tickets')->has('tickets', 1));

        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('ticketchat')->has('messages', 2));
    }

    public function test_client_message_gets_team_reply(): void
    {
        $client = $this->client();
        $this->actingAs($client)->post('/tickets', ['type' => 'استشارة']);
        $ticket = Ticket::firstOrFail();

        $this->actingAs($client)
            ->post(route('tickets.messages.store', $ticket), ['body' => 'متى يكتمل طلبي؟'])
            ->assertNoContent();

        // رسالة العميل + ردّ الفريق (الاحتياطي عند غياب مفتاح API)
        $msgs = $ticket->fresh()->messages;
        $this->assertSame('متى يكتمل طلبي؟', $msgs[2]->body);
        $this->assertSame('ai', $msgs[3]->who);
    }

    public function test_client_cannot_see_internal_notes(): void
    {
        $client = $this->client();
        $this->actingAs($client)->post('/tickets', ['type' => 'استشارة']);
        $ticket = Ticket::firstOrFail();

        $this->actingAs($this->employee())
            ->post(route('employee.tickets.note', $ticket), ['body' => 'ملاحظة داخلية سرية'])
            ->assertNoContent();

        // الموظف يراها، العميل لا
        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('messages', fn ($m) => collect($m)->doesntContain(fn ($x) => $x['who'] === 'note')));
    }

    public function test_document_gate_blocks_referral_until_attachment(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $employee = $this->employee();

        $this->actingAs($client)->post('/tickets', ['type' => 'نزاع تجاري']);
        $ticket = Ticket::firstOrFail();

        // لا مرفقات → محاولة الإحالة تُحوّل الحالة إلى «بانتظار مستندات» ولا تتقدّم
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار مستندات', $ticket->fresh()->status);
        $this->assertSame(0, $ticket->fresh()->attachments);

        // العميل يرفق مستنداً
        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'),
        ])->assertNoContent();
        $this->assertSame(1, $ticket->fresh()->attachments);

        // الآن الإحالة تُسمح — تُجهَّز للمستشار وتنتظر اعتماده
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->summary);
    }

    public function test_full_journey_completes_with_lawyer_approval(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $employee = $this->employee();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'أ. سارة القحطاني']);

        $this->actingAs($client)->post('/tickets', ['type' => 'نزاع تجاري']);
        $ticket = Ticket::firstOrFail();

        // تجاوز بوابة المستندات بإرفاق ملف
        $this->actingAs($client)->post(route('tickets.attach', $ticket), [
            'file' => UploadedFile::fake()->create('id.pdf', 50),
        ])->assertNoContent();

        // الإحالة → بانتظار اعتماد المستشار
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار اعتماد المستشار', $ticket->fresh()->status);

        // اعتماد المستشار للملخص → الرأي القانوني
        $this->actingAs($lawyer)->post(route('lawyer.summary.approve', $ticket))->assertRedirect();
        $this->assertSame('الرأي القانوني', $ticket->fresh()->status);

        // الموظف يتقدّم → بانتظار حجز الاستشارة
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار حجز الاستشارة', $ticket->fresh()->status);

        // العميل يحجز الاستشارة → موعد مؤكد (بالمعرّف ووقت حقيقي)
        $this->actingAs($client)->post(route('tickets.book', $ticket), [
            'type' => 'video', 'lawyer_id' => $lawyer->id,
            'date' => LawyerAvailability::resolveDate(null)->toDateString(), 'time' => '11:30',
        ])->assertNoContent();
        $this->assertSame('موعد مؤكد', $ticket->fresh()->status);

        // الموظف يعقد الجلسة → بانتظار اعتماد النتيجة
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('بانتظار اعتماد النتيجة', $ticket->fresh()->status);

        // المستشار يعتمد النتيجة → بانتظار اعتماد الإدارة
        $this->actingAs($lawyer)->post(route('lawyer.result.approve', $ticket))->assertRedirect();
        $this->assertSame('بانتظار اعتماد الإدارة', $ticket->fresh()->status);

        // الإدارة تعتمد نهائياً → مكتملة
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.tickets.result', $ticket))->assertRedirect();
        $this->assertSame('مكتملة', $ticket->fresh()->status);

        // بعد الاكتمال لا تتغيّر الحالة
        $this->actingAs($employee)->post(route('employee.tickets.advance', $ticket))->assertNoContent();
        $this->assertSame('مكتملة', $ticket->fresh()->status);
    }

    public function test_client_cannot_access_another_clients_ticket(): void
    {
        $owner = $this->client();
        $this->actingAs($owner)->post('/tickets', ['type' => 'استشارة']);
        $ticket = Ticket::firstOrFail();

        $intruder = $this->client();
        $this->actingAs($intruder)->get(route('tickets.show', $ticket))->assertForbidden();
    }
}
