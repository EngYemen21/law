<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **ما يرفقه العميل عند فتح التذكرة يظهر في محادثتها** (2026-09-20).
 *
 * كان `TicketController::store` يخزّن الملفّ ويكتب صفّه في `ticket_documents` **بلا رسالة**،
 * بينما مسار الإرفاق اللاحق (`attach`) يكتب رسالةً بشارةٍ قابلة للتنزيل. فالمرفق عند الفتح
 * لا يراه أحد في المحادثة — لا العميل ولا الطاقم (ولا شاشة للطاقم تعرضه أصلاً)، فيُطلب من
 * العميل مستندٌ أرسله فعلاً.
 *
 * ويحرس الاختبار الترتيب أيضاً: رسالة العميل ثمّ مرفقاته، ثمّ ترحيب الوكيل بعدهما.
 */
class OpeningAttachmentsShowInChatTest extends TestCase
{
    use RefreshDatabase;

    private function openWithFiles(User $client, array $files): Ticket
    {
        $this->actingAs($client)->post(route('tickets.store'), [
            'type' => 'نزاع تجاري',
            'department' => 'القسم التجاري',
            'details' => 'أطالب بمستحقاتي بموجب العقد المرفق.',
            'files' => $files,
        ])->assertRedirect();

        return Ticket::latest('id')->firstOrFail();
    }

    public function test_the_attachment_appears_as_a_downloadable_chip_for_the_client(): void
    {
        Storage::fake('local');
        Queue::fake(); // ترحيب الوكيل خارج نطاق هذا الاختبار
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = $this->openWithFiles($client, [UploadedFile::fake()->create('عقد-البيع.pdf', 90, 'application/pdf')]);

        $doc = $ticket->documents()->sole();
        $chip = $ticket->messages()->where('body', 'like', '%doc-chip%')->sole();
        $this->assertStringContainsString('عقد-البيع.pdf', $chip->body, 'اسم المرفق غائب عن المحادثة.');
        $this->assertStringContainsString(route('files.download', ['type' => 'ticket', 'id' => $doc->id]), $chip->body, 'الشارة بلا رابط تنزيل.');

        // ويصل العميلَ فعلاً في صفحته
        $this->actingAs($client)->get(route('tickets.show', $ticket))
            ->assertInertia(fn ($p) => $p->where('messages', fn ($msgs) => collect($msgs)->contains(
                fn ($m) => str_contains((string) $m['text'], 'عقد-البيع.pdf')
            )));
    }

    /** الطاقم يرى المرفق نفسه في محادثته — كان غائباً عنه تماماً. */
    public function test_the_staff_sees_the_same_attachment_in_the_conversation(): void
    {
        Storage::fake('local');
        Queue::fake();
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $ticket = $this->openWithFiles($client, [UploadedFile::fake()->create('كشف-حساب.pdf', 40, 'application/pdf')]);

        $this->actingAs($employee)->get(route('employee.tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('messages', fn ($msgs) => collect($msgs)->contains(
                fn ($m) => str_contains((string) $m['text'], 'كشف-حساب.pdf')
            )));
    }

    /** مرفقاتٌ عدّة في رسالةٍ واحدة لا رسالةٍ لكلّ ملفّ — ولا تتكرّر على العميل. */
    public function test_several_files_arrive_in_one_message(): void
    {
        Storage::fake('local');
        Queue::fake();
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = $this->openWithFiles($client, [
            UploadedFile::fake()->create('عقد.pdf', 30, 'application/pdf'),
            UploadedFile::fake()->create('فاتورة.pdf', 30, 'application/pdf'),
        ]);

        $chips = $ticket->messages()->where('body', 'like', '%doc-chip%')->get();
        $this->assertCount(1, $chips);
        $this->assertStringContainsString('عقد.pdf', $chips->first()->body);
        $this->assertStringContainsString('فاتورة.pdf', $chips->first()->body);
    }

    /**
     * الترتيب: رسالة العميل ← مرفقاته ← ترحيب الوكيل. الترحيب يُكتب في مهمّةٍ خلفيّة، فلو
     * سبق المرفقات لقرأه العميل قبل أن يرى ما أرسله، ولما عرف الوكيلُ سياقَ مرفقاته.
     */
    public function test_the_attachment_message_precedes_the_agent_greeting(): void
    {
        Storage::fake('local');
        config(['services.ai_agent.enabled' => true]);
        $client = User::factory()->create(['role' => Role::Client]);

        $ticket = $this->openWithFiles($client, [UploadedFile::fake()->create('عقد.pdf', 30, 'application/pdf')]);

        $ordered = $ticket->messages()->orderBy('id')->get();
        $this->assertSame('client', $ordered->first()->who, 'أوّل ما في المحادثة ليس رسالة العميل.');
        $chipIndex = $ordered->search(fn ($m) => str_contains((string) $m->body, 'doc-chip'));
        $aiIndex = $ordered->search(fn ($m) => $m->who === 'ai');
        $this->assertNotFalse($chipIndex, 'رسالة المرفقات غائبة.');
        if ($aiIndex !== false) {
            $this->assertLessThan($aiIndex, $chipIndex, 'ترحيب الوكيل سبق مرفقات العميل.');
        }
    }
}
