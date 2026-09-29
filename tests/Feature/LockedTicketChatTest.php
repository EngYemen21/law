<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\TicketStatus;
use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * «تم حسم قرار مآل التذكرة واكتمال الملف (أرشيف للقراءة فقط)» — المحادثة مغلقة للأطراف الثلاثة.
 *
 * بعد اعتماد المآل قضيّةً أو تنفيذاً أو إغلاقاً تُجمَّد التذكرة: لا رسالة ولا ملاحظة ولا مرفق
 * من العميل أو المحامي أو الموظّف. الإغلاق حكم الخادم لا إخفاء الصندوق؛ ومسار الاستشارة
 * وحده يُبقي المحادثة مفتوحة لأنّ التذكرة تكمل رحلتها.
 */
class LockedTicketChatTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $this->employee = User::factory()->create(['role' => Role::Employee]);
        $this->employee->givePermissionTo(Permissions::REPLY_TO_CLIENTS);
    }

    private function ticket(TicketStatus $status, bool $frozen): Ticket
    {
        return Ticket::create([
            'user_id' => $this->client->id,
            'number' => 'SB-LOCK-'.uniqid(),
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'assigned_lawyer_id' => $this->lawyer->id,
            'assigned_lawyer' => $this->lawyer->name,
            'status' => $status->value,
            'tone' => 'b-grey',
            'is_frozen' => $frozen,
        ]);
    }

    /** كلّ طرق الكتابة في محادثة التذكرة، بالدور الذي يملكها — رمز الاستجابة لكلٍّ منها. */
    private function writeAttempts(Ticket $ticket): array
    {
        $file = fn () => ['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')];

        return [
            'client message' => $this->actingAs($this->client)->post(route('tickets.messages.store', $ticket), ['body' => 'رسالة'])->status(),
            'client attach' => $this->actingAs($this->client)->post(route('tickets.attach', $ticket), $file())->status(),
            'lawyer reply' => $this->actingAs($this->lawyer)->post(route('lawyer.tickets.reply', $ticket), ['body' => 'رد'])->status(),
            'lawyer note' => $this->actingAs($this->lawyer)->post(route('lawyer.tickets.note', $ticket), ['body' => 'ملاحظة'])->status(),
            'employee reply' => $this->actingAs($this->employee)->post(route('employee.tickets.reply', $ticket), ['body' => 'رد'])->status(),
            'employee note' => $this->actingAs($this->employee)->post(route('employee.tickets.note', $ticket), ['body' => 'ملاحظة'])->status(),
            'employee attach' => $this->actingAs($this->employee)->post(route('employee.tickets.attach', $ticket), $file())->status(),
        ];
    }

    public function test_a_decided_ticket_refuses_every_write_from_every_party(): void
    {
        foreach ([TicketStatus::ConvertedToCase, TicketStatus::ConvertedToExecution, TicketStatus::Closed] as $status) {
            $ticket = $this->ticket($status, frozen: true);

            foreach ($this->writeAttempts($ticket) as $attempt => $code) {
                $this->assertNotSame(204, $code, "{$status->value}: {$attempt} كُتب في أرشيفٍ للقراءة فقط");
            }

            $this->assertSame(0, $ticket->messages()->count(), "{$status->value}: لا رسالة تُحفظ");
            $this->assertSame(0, $ticket->documents()->count(), "{$status->value}: ولا مرفق");
        }
    }

    public function test_the_consultation_track_keeps_the_chat_open(): void
    {
        $ticket = $this->ticket(TicketStatus::AwaitingBooking, frozen: false);

        foreach ($this->writeAttempts($ticket) as $attempt => $code) {
            $this->assertSame(204, $code, "{$attempt}: مسار الاستشارة لا يُغلق المحادثة");
        }
    }

    public function test_every_chat_page_locks_its_composer_from_the_server_flags(): void
    {
        $pages = ['ticketchat', 'lawyer/ticketchat', 'employee/ticketchat'];

        foreach ($pages as $page) {
            $src = (string) file_get_contents(resource_path("js/pages/{$page}.tsx"));

            $this->assertStringContainsString('isTerminal', $src, "{$page}: الإغلاق من حكم الخادم");
            $this->assertStringContainsString('isFrozen', $src, "{$page}: والتجميد");
            $this->assertDoesNotMatchRegularExpression(
                "/\\[\\s*'محولة إلى قضية'/u",
                $src,
                "{$page}: قائمة نصوص حالاتٍ عربيّة في منطق الإغلاق",
            );
        }
    }
}
