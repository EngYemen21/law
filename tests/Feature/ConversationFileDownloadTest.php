<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseDocument;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\LegalCase;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\User;
use App\Support\ConversationFiles;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **مرفقاتُ المحادثات تُنزَّل — لكلّ طرفٍ في الملفّ، ولا لغيره.**
 *
 * كانت شارةُ المرفق في محادثات التذكرة والقضيّة والتنفيذ نصّاً بلا رابط لكلّ الأدوار،
 * والموظّف بلا مسار تنزيلٍ أصلاً. قرار المالك (2026-09-11): المحامي والإدارة العليا
 * والموظّف والعميل يُنزّلون — والقاعدة في `ConversationFiles::canDownload`.
 */
class ConversationFileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake(); // التحليل الذكيّ للمستند خارج موضوع هذا الاختبار

        foreach (['إدارة التذاكر', 'إدارة القضايا والأتعاب', 'الرد على العملاء', Permissions::DOWNLOAD_FILES] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->client = User::factory()->create(['role' => Role::Client]);
        $this->lawyer = User::factory()->create(['role' => Role::Lawyer]);
    }

    private function file(string $path): string
    {
        Storage::disk('local')->put($path, '%PDF-1.4 test');

        return $path;
    }

    /** @return array{0: Model, 1: Model} [الملفّ، المستند] */
    private function make(string $type, string $name = 'صك الملكية.pdf'): array
    {
        $owner = ['user_id' => $this->client->id, 'assigned_lawyer_id' => $this->lawyer->id];

        return match ($type) {
            'ticket' => (function () use ($owner, $name) {
                $t = Ticket::create($owner + ['number' => 'TK-F-'.uniqid(), 'type' => 'استشارة', 'status' => 'جديدة']);

                return [$t, TicketDocument::create(['ticket_id' => $t->id, 'name' => $name, 'path' => $this->file("ticket-docs/{$t->id}/a.pdf"), 'mime' => 'application/pdf', 'size' => 13])];
            })(),
            'case' => (function () use ($owner, $name) {
                $c = LegalCase::create($owner + ['number' => 'C-F-'.uniqid(), 'type' => 'تجارية', 'status' => 'جارية']);

                return [$c, CaseDocument::create(['case_id' => $c->id, 'name' => $name, 'path' => $this->file("case-docs/{$c->id}/a.pdf"), 'mime' => 'application/pdf', 'size' => 13])];
            })(),
            'exec' => (function () use ($owner, $name) {
                $e = Execution::create($owner + ['number' => 'EX-F-'.uniqid(), 'subject' => 'تنفيذ حكم', 'stage' => 2]);

                return [$e, ExecutionDocument::create(['execution_id' => $e->id, 'label' => $name, 'status' => 'مرفوع', 'path' => $this->file("exec-docs/{$e->id}/a.pdf"), 'mime' => 'application/pdf', 'size' => 13])];
            })(),
        };
    }

    private function employee(array $permissions): User
    {
        $u = User::factory()->create(['role' => Role::Employee]);
        $u->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u;
    }

    // ————— ١ · القاعدة: الأطراف الأربعة يُنزّلون، وغيرهم لا —————

    public function test_every_party_of_the_file_downloads_and_no_one_else(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $stranger = User::factory()->create(['role' => Role::Client]);
        $otherLawyer = User::factory()->create(['role' => Role::Lawyer]);

        foreach (['ticket' => 'إدارة التذاكر', 'case' => 'إدارة القضايا والأتعاب', 'exec' => 'إدارة القضايا والأتعاب'] as $type => $perm) {
            [, $doc] = $this->make($type);
            $url = ConversationFiles::url($type, $doc->id);

            foreach ([
                'العميل صاحب الملفّ' => [$this->client, 200],
                'المحامي المسنَد' => [$this->lawyer, 200],
                'الإدارة العليا' => [$admin, 200],
                'الموظّف بصلاحيّة المحادثة والتنزيل' => [$this->employee([$perm, Permissions::DOWNLOAD_FILES]), 200],
                // قرار المالك 2026-09-18: رؤية المحادثة لا تكفي — التنزيل صلاحيّةٌ مستقلّة
                'الموظّف بصلاحيّة المحادثة وحدها' => [$this->employee([$perm]), 403],
                'الموظّف بصلاحيّة التنزيل وحدها' => [$this->employee([Permissions::DOWNLOAD_FILES]), 403],
                'عميلٌ آخر' => [$stranger, 403],
                'محامٍ غير مسنَد' => [$otherLawyer, 403],
                'موظّفٌ بلا صلاحيّة' => [$this->employee([]), 403],
            ] as $who => [$user, $status]) {
                $res = $this->actingAs($user)->get($url);
                // الرابط يُفتح في المتصفّح: المرفوض يعود بسببه إشعاراً لا صفحة ٤٠٣ (`ErrorResponse`)
                if ($status === 403) {
                    $this->assertSame(303, $res->getStatusCode(), "{$type} · {$who}: مرفوض");
                    $this->assertPageRefused($res);

                    continue;
                }
                $this->assertSame($status, $res->getStatusCode(), "{$type} · {$who}");
                if ($status === 200) {
                    $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'), "{$type} · {$who}: ليس تنزيلاً");
                }
            }
        }
    }

    public function test_a_missing_file_is_a_clean_404_and_unknown_types_are_refused(): void
    {
        [, $doc] = $this->make('ticket');
        Storage::disk('local')->delete($doc->path);

        $this->actingAs($this->client)->get(ConversationFiles::url('ticket', $doc->id))->assertNotFound();
        $this->actingAs($this->client)->get('/files/meeting/1')->assertNotFound();
        $this->actingAs($this->client)->get('/files/ticket/999999')->assertNotFound();
    }

    // ————— ٢ · المرفقُ الجديد رابطٌ من لحظته —————

    public function test_every_attach_path_writes_a_download_link_into_the_conversation(): void
    {
        $employee = $this->employee(['إدارة التذاكر', 'إدارة القضايا والأتعاب', 'الرد على العملاء']);
        $pdf = fn () => UploadedFile::fake()->create('عقد التوريد.pdf', 12, 'application/pdf');

        [$ticket] = $this->make('ticket');
        [$case] = $this->make('case');
        [$exec] = $this->make('exec');

        $this->actingAs($this->client)->post(route('tickets.attach', $ticket), ['file' => $pdf()])->assertSuccessful();
        $this->actingAs($employee)->post(route('employee.tickets.attach', $ticket), ['file' => $pdf()])->assertSuccessful();
        $this->actingAs($this->client)->post(route('cases.attach', $case), ['file' => $pdf()])->assertSuccessful();
        $this->actingAs($employee)->post(route('employee.cases.attach', $case), ['file' => $pdf()])->assertRedirect();
        $this->actingAs($this->lawyer)->post(route('lawyer.cases.attach', $case), ['file' => $pdf()])->assertRedirect();
        $this->actingAs($this->client)->post(route('exec-flow.attach', $exec), ['file' => $pdf()])->assertSuccessful();

        $checks = [
            'ticket' => [$ticket->messages()->orderBy('id')->get(), $ticket->documents()->reorder('id')->get()],
            'case' => [$case->messages()->orderBy('id')->get(), $case->documents()->reorder('id')->get()],
            'exec' => [$exec->messages()->orderBy('id')->get(), $exec->documents()->reorder('id')->get()],
        ];

        foreach ($checks as $type => [$messages, $docs]) {
            // المستند الأوّل من `make()`؛ ما بعده رُفع الآن — لكلٍّ منها رسالةٌ برابطه هو
            $uploaded = $docs->slice(1)->values();
            $this->assertNotEmpty($uploaded, "{$type}: لم يُرفع شيء");
            foreach ($uploaded as $doc) {
                $href = e(ConversationFiles::url($type, $doc->id));
                $this->assertTrue(
                    $messages->contains(fn ($m) => str_contains($m->body, 'href="'.$href.'"') && str_contains($m->body, 'doc-chip-link')),
                    "{$type}: رسالة الإرفاق بلا رابط تنزيل للمستند {$doc->id}"
                );
            }
        }
    }

    // ————— ٣ · الرسائل القديمة تُربط بالاسم — بلا تخمين —————

    public function test_legacy_chips_are_linked_by_name_only_when_unambiguous(): void
    {
        [$ticket, $doc] = $this->make('ticket', 'عقد.pdf');
        TicketDocument::create(['ticket_id' => $ticket->id, 'name' => 'مكرّر.pdf', 'path' => $this->file('x/1.pdf')]);
        TicketDocument::create(['ticket_id' => $ticket->id, 'name' => 'مكرّر.pdf', 'path' => $this->file('x/2.pdf')]);

        $messages = ConversationFiles::linkLegacyChips([
            ['text' => '<div class="doc-list"><span class="doc-chip">📎 عقد.pdf</span></div>'],
            ['text' => '<div class="doc-list"><span class="doc-chip">📎 مكرّر.pdf</span></div>'],
            ['text' => '<div class="doc-list"><span class="doc-chip">📄 النوع: عقد</span><span class="doc-chip">صورة الهوية</span></div>'],
            ['text' => '<span class="doc-chip">📎 غير موجود.pdf</span>'],
        ], 'ticket', $ticket->documents()->get());

        $this->assertStringContainsString('href="'.e(ConversationFiles::url('ticket', $doc->id)).'"', $messages[0]['text']);
        $this->assertStringNotContainsString('href=', $messages[1]['text'], 'اسمٌ لمستندين — لا يُخمَّن أيّهما');
        $this->assertStringNotContainsString('href=', $messages[2]['text'], 'الشارات غير المرفقة ليست ملفّات');
        $this->assertStringNotContainsString('href=', $messages[3]['text'], 'لا مستند بهذا الاسم');
    }

    public function test_every_role_screen_serves_linked_legacy_chips(): void
    {
        [$ticket, $tdoc] = $this->make('ticket', 'عقد.pdf');
        $ticket->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => '<p>تم إرفاق مستند:</p><div class="doc-list"><span class="doc-chip">📎 عقد.pdf</span></div>']);
        [$case, $cdoc] = $this->make('case', 'صك.pdf');
        $case->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => '<p>تم إرفاق مستند:</p><div class="doc-list"><span class="doc-chip">📎 صك.pdf</span></div>']);

        $tHref = 'href="'.e(ConversationFiles::url('ticket', $tdoc->id)).'"';
        $cHref = 'href="'.e(ConversationFiles::url('case', $cdoc->id)).'"';
        $has = fn (string $href) => fn ($messages) => collect($messages)->contains(fn ($m) => str_contains((string) $m['text'], $href));

        $admin = User::factory()->create(['role' => Role::Admin]);
        $employee = $this->employee(['إدارة التذاكر', 'إدارة القضايا والأتعاب']);

        foreach ([
            [$this->client, route('tickets.show', $ticket), $tHref],
            [$employee, route('employee.tickets.show', $ticket), $tHref],
            [$this->lawyer, route('lawyer.tickets.show', $ticket), $tHref],
            [$admin, route('admin.tickets.show', $ticket), $tHref],
            [$this->client, route('cases.show', $case), $cHref],
            [$employee, route('employee.cases.show', $case), $cHref],
            [$this->lawyer, route('lawyer.cases.show', $case), $cHref],
        ] as [$user, $url, $href]) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertInertia(fn ($p) => $p->where('messages', $has($href)));
        }
    }

    public function test_the_execution_card_links_chips_where_documents_are_loaded(): void
    {
        [$exec, $doc] = $this->make('exec', 'سند.pdf');
        $exec->messages()->create(['who' => 'client', 'name' => 'أنت', 'role' => 'العميل', 'body' => '<span class="doc-chip">📎 سند.pdf</span>']);

        $card = $exec->fresh()->load(['messages', 'documents'])->toFlowCard(true, true);

        $this->assertStringContainsString('href="'.e(ConversationFiles::url('exec', $doc->id)).'"', $card['messages'][0]['text']);
    }
}
