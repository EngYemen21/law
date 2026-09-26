<?php

namespace Tests\Feature;

use App\Enums\RequirementCheck;
use App\Enums\Role;
use App\Models\LegalDepartment;
use App\Models\LegalDepartmentDocument;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Models\TicketDocumentRequirement;
use App\Models\User;
use App\Support\DepartmentDocumentsSeed;
use App\Support\LegalCatalogue;
use App\Support\TicketDocumentRequirements;
use App\Support\TicketTriage;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **قائمة مستندات القسم و«النواقص» لكلّ تذكرة** (قرار المالك 2026-09-26).
 *
 * يحرس: زرع القوائم القديمة على الأقسام، وتحريرها من شاشة الكتالوج للإدارة وحدها، وحساب الاستيفاء،
 * ومطابقة الفحص الآليّ عبر البوّابة (وسقوطها حين يُطفأ)، والتأكيد اليدويّ، وأنّ طلب النواقص لا يطلب
 * ما وصل ويسِم الاختياريّ — وأنّ قائمةً ثابتة لا تعود إلى الشيفرة.
 */
class TicketDocumentRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private function department(string $code): LegalDepartment
    {
        return LegalDepartment::where('code', $code)->firstOrFail();
    }

    private function ticketIn(string $code, array $attributes = []): Ticket
    {
        return Ticket::create($attributes + [
            'user_id' => User::factory()->create(['role' => Role::Client])->id,
            'number' => 'TK-REQ-'.uniqid(),
            'type' => 'اختبار', 'subject' => 'اختبار',
            'department' => $this->department($code)->name,
            'status' => 'بانتظار مستندات', 'tone' => 'b-amber',
        ]);
    }

    private function attach(Ticket $ticket, string $name, string $content = 'نصّ المستند'): TicketDocument
    {
        $path = "ticket-docs/{$ticket->id}/".uniqid().'-'.$name;
        Storage::disk('local')->put($path, $content);

        return $ticket->documents()->create([
            'name' => $name, 'path' => $path, 'mime' => 'text/plain', 'size' => strlen($content), 'status' => 'قيد الفحص',
        ]);
    }

    /** @return list<string> */
    private function names(array $items): array
    {
        return array_column($items, 'name');
    }

    // ── الزرع ──

    public function test_the_old_lists_are_seeded_onto_their_departments(): void
    {
        $commercial = LegalCatalogue::documentsFor($this->department('commercial')->id);
        $this->assertSame(['الهوية', 'العقد', 'المراسلات'], $this->names($commercial));
        $this->assertSame([true, true, false], array_column($commercial, 'required'));

        // «طلاق وفسخ» و«حضانة ونفقة» في قسمٍ واحد — تُدمج بلا تكرار، و«إثبات الدخل» اختياريّ
        $family = LegalCatalogue::documentsFor($this->department('personal_status')->id);
        $this->assertSame(['الهوية', 'عقد النكاح', 'سجل الأسرة', 'إثبات الدخل'], $this->names($family));
        $this->assertFalse($family[3]['required']);

        // قسمٌ لا قائمة قديمة له ⇒ القائمة العامّة (بلا معرّفات)
        $medical = LegalCatalogue::documentsFor($this->department('medical')->id);
        $this->assertSame($this->names(LegalCatalogue::DEFAULT_DOCUMENTS), $this->names($medical));
        $this->assertNull($medical[0]['id']);
    }

    public function test_the_seed_is_idempotent_and_names_departments_needing_the_owner(): void
    {
        $before = LegalDepartmentDocument::count();
        $report = DepartmentDocumentsSeed::run(dryRun: true);

        $this->assertSame([], $report['unresolved'], 'كلّ نوعٍ قديم يطابق قسماً');
        $this->assertTrue(collect($report['departments'])->every(fn ($row) => $row['action'] === 'skipped'), 'قائمةٌ قائمة لا يُكتب فوقها');
        $this->assertContains($this->department('medical')->name, $report['needsOwner']);
        $this->assertNotContains($this->department('commercial')->name, $report['needsOwner']);

        DepartmentDocumentsSeed::run();
        $this->assertSame($before, LegalDepartmentDocument::count());

        $this->artisan('catalogue:seed-documents', ['--dry-run' => true])->assertSuccessful();
    }

    // ── التحرير من شاشة الكتالوج ──

    public function test_management_edits_a_department_list_and_others_cannot(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $medical = $this->department('medical');

        $this->actingAs($admin)->post(route('admin.catalogue.documents.store', $medical), ['name' => 'التقرير الطبي', 'required' => true])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.catalogue.documents.store', $medical), ['name' => 'الفواتير', 'required' => false])->assertRedirect();

        $report = LegalDepartmentDocument::where('legal_department_id', $medical->id)->where('name', 'التقرير الطبي')->firstOrFail();
        $bills = LegalDepartmentDocument::where('legal_department_id', $medical->id)->where('name', 'الفواتير')->firstOrFail();
        // **أوّل مستندٍ يُضاف لقسمٍ بلا قائمة يَنسخ القائمة العامّة صفوفاً قابلة للتعديل قبله** (2026-09-26) —
        // كانت إضافته تُسقط «الهوية الوطنية» وأخواتها بصمت، فيقلّ ما يُطلب من العميل دون أن يقصده أحد
        $defaults = $this->names(LegalCatalogue::DEFAULT_DOCUMENTS);
        $this->assertSame([...$defaults, 'التقرير الطبي', 'الفواتير'], $this->names(LegalCatalogue::documentsFor($medical->id)));

        // اسمٌ مطابقٌ بعد توحيد الصياغة يُرفض
        $this->actingAs($admin)->post(route('admin.catalogue.documents.store', $medical), ['name' => 'التقرير  الطبى', 'required' => true])
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)->put(route('admin.catalogue.documents.update', $report), ['name' => 'التقرير الطبي المعتمد'])->assertRedirect();
        $this->actingAs($admin)->put(route('admin.catalogue.documents.update', $bills), ['required' => true])->assertRedirect();
        $defaultIds = LegalDepartmentDocument::where('legal_department_id', $medical->id)->whereIn('name', $defaults)->orderBy('sort_order')->pluck('id')->all();
        $this->actingAs($admin)->post(route('admin.catalogue.documents.reorder', $medical), ['order' => [$bills->id, $report->id, ...$defaultIds]])->assertRedirect();

        $this->assertSame(['الفواتير', 'التقرير الطبي المعتمد', ...$defaults], $this->names(LegalCatalogue::documentsFor($medical->id)));
        $this->assertTrue($bills->refresh()->required);

        $this->actingAs($admin)->delete(route('admin.catalogue.documents.destroy', $bills))->assertRedirect();
        $this->assertSame(['التقرير الطبي المعتمد', ...$defaults], $this->names(LegalCatalogue::documentsFor($medical->id)));

        // الشاشة تعرض القائمة والقائمة العامّة
        $this->actingAs($admin)->get(route('admin.catalogue'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalogue')
            ->where('defaultDocuments', LegalCatalogue::DEFAULT_DOCUMENTS)
            ->has('departments.0.documents'));

        foreach ([Role::Employee, Role::Lawyer, Role::Client] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post(route('admin.catalogue.documents.store', $medical), ['name' => 'مستند', 'required' => true])
                ->assertRedirect();
        }
        $this->assertFalse(LegalDepartmentDocument::where('name', 'مستند')->exists());
    }

    // ── حساب الاستيفاء ──

    public function test_status_reflects_matches_with_required_first(): void
    {
        $ticket = $this->ticketIn('commercial');
        $contract = $this->attach($ticket, 'contract.txt');

        TicketDocumentRequirements::recordAiCheck($contract, ['العقد', 'بندٌ ليس في القائمة']);

        $status = collect(TicketDocumentRequirements::for($ticket))->keyBy('name');
        $this->assertTrue($status['العقد']['satisfied']);
        $this->assertSame('ai', $status['العقد']['checkedBy']);
        $this->assertSame($contract->id, $status['العقد']['matchedDocumentId']);
        $this->assertFalse($status['الهوية']['satisfied']);
        $this->assertSame(1, TicketDocumentRequirement::count(), 'اسمٌ خارج القائمة يُسقط');

        $this->assertSame([['name' => 'الهوية', 'required' => true], ['name' => 'المراسلات', 'required' => false]], TicketDocumentRequirements::missing($ticket));
        $this->assertNotNull($contract->refresh()->requirements_checked_at);
    }

    public function test_a_renamed_item_stays_satisfied(): void
    {
        $ticket = $this->ticketIn('commercial');
        $contract = $this->attach($ticket, 'contract.txt');
        TicketDocumentRequirements::recordAiCheck($contract, ['العقد']);

        LegalDepartmentDocument::where('legal_department_id', $ticket->legal_department_id)->where('name', 'العقد')->update(['name' => 'العقد محل النزاع']);
        LegalCatalogue::flush();

        $this->assertNotContains('العقد محل النزاع', $this->names(TicketDocumentRequirements::missing($ticket)));
    }

    // ── الفحص الآليّ عبر البوّابة ──

    private function fakeAnalysis(array $payload): void
    {
        config(['services.gemini.key' => 'test-key', 'services.glm.key' => '', 'services.ai_agent.enabled' => true]);
        Cache::flush();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]]]],
            ], 200),
        ]);
    }

    public function test_the_document_check_stores_the_matched_item_and_sends_the_list(): void
    {
        $this->fakeAnalysis(['related' => true, 'doc_type' => 'عقد توريد', 'summary' => 'عقد بين الطرفين', 'reason' => 'محل النزاع', 'requirements' => ['العقد', 'صك الملكية']]);
        $ticket = $this->ticketIn('commercial', ['status' => 'قيد التحليل']);
        $doc = $this->attach($ticket, 'contract.txt', 'عقد توريد بين شركة أ وشركة ب');

        TicketTriage::onDocumentAttached($ticket, $doc);

        $match = TicketDocumentRequirement::sole();
        $this->assertSame('العقد', $match->requirement);
        $this->assertSame(RequirementCheck::Ai, $match->checked_by);
        $this->assertNotNull($doc->refresh()->requirements_checked_at);

        // النموذج رأى القائمة بإلزامها
        Http::assertSent(function ($request) {
            $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($sent, 'قائمة المستندات المطلوبة لقسم الطلب') && str_contains($sent, 'المراسلات — اختياريّ');
        });
    }

    public function test_an_unrelated_document_satisfies_nothing(): void
    {
        $this->fakeAnalysis(['related' => false, 'doc_type' => 'فاتورة', 'summary' => 'فاتورة كهرباء', 'reason' => 'لا صلة', 'requirements' => ['العقد']]);
        $ticket = $this->ticketIn('commercial', ['status' => 'قيد التحليل']);
        $doc = $this->attach($ticket, 'bill.txt');

        TicketTriage::onDocumentAttached($ticket, $doc);

        $this->assertSame(0, TicketDocumentRequirement::count());
        $this->assertNotNull($doc->refresh()->requirements_checked_at, 'فُحص فعلاً — لا يبقى «لم يُتحقّق»');
    }

    public function test_a_switched_off_check_marks_nothing_and_the_request_says_so(): void
    {
        $this->fakeAnalysis(['related' => true, 'doc_type' => 'عقد', 'summary' => 'عقد', 'reason' => '', 'requirements' => ['العقد']]);
        Setting::put('ai_enabled_tasks', json_encode(['document.analyze' => false]));
        $ticket = $this->ticketIn('commercial', ['status' => 'قيد التحليل']);
        $doc = $this->attach($ticket, 'contract.txt');

        TicketTriage::onDocumentAttached($ticket, $doc);

        $this->assertSame(0, TicketDocumentRequirement::count());
        $this->assertNull($doc->refresh()->requirements_checked_at);
        Http::assertNothingSent();

        $html = TicketDocumentRequirements::requestHtml($ticket, 'نأمل إرفاق:');
        $this->assertStringContainsString('العقد', $html);
        $this->assertStringContainsString(e(TicketDocumentRequirements::UNCHECKED_NOTE), $html, 'لا يُعيد العميل إرفاق ما أرسله');
    }

    // ── التأكيد اليدويّ ──

    public function test_staff_mark_and_unmark_an_item(): void
    {
        $this->seed(PermissionSeeder::class);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', ['إدارة التذاكر'])->get());
        $ticket = $this->ticketIn('commercial');
        $doc = $this->attach($ticket, 'id.txt');

        $this->actingAs($employee)->postJson(route('employee.tickets.requirements.update', $ticket), ['requirement' => 'الهوية', 'document_id' => $doc->id])
            ->assertOk()
            ->assertJsonPath('items.0.name', 'الهوية')
            ->assertJsonPath('items.0.satisfied', true)
            ->assertJsonPath('items.0.checkedBy', 'staff');

        $this->assertSame(RequirementCheck::Staff, TicketDocumentRequirement::sole()->checked_by);
        $this->assertNotContains('الهوية', $this->names(TicketDocumentRequirements::missing($ticket)));
        $this->assertTrue($ticket->messages()->where('who', 'note')->where('role', 'قائمة المستندات')->exists());

        // بندٌ ليس في القائمة، ومستندٌ من تذكرةٍ أخرى — يُرفضان
        $this->actingAs($employee)->postJson(route('employee.tickets.requirements.update', $ticket), ['requirement' => 'جواز السفر', 'document_id' => $doc->id])->assertUnprocessable();
        $other = $this->attach($this->ticketIn('commercial'), 'x.txt');
        $this->actingAs($employee)->postJson(route('employee.tickets.requirements.update', $ticket), ['requirement' => 'العقد', 'document_id' => $other->id])->assertNotFound();

        $this->actingAs($employee)->postJson(route('employee.tickets.requirements.update', $ticket), ['requirement' => 'الهوية', 'document_id' => null])
            ->assertOk()->assertJsonPath('items.0.satisfied', false);
        $this->assertSame(0, TicketDocumentRequirement::count());

        // المحامي على ما أُسند إليه وحده
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $this->actingAs($lawyer)->getJson(route('lawyer.tickets.requirements', $ticket))->assertForbidden();
        $ticket->update(['assigned_lawyer_id' => $lawyer->id]);
        $this->actingAs($lawyer)->getJson(route('lawyer.tickets.requirements', $ticket))->assertOk()->assertJsonPath('uncheckedCount', 0); // التأكيد اليدويّ فحصٌ للمرفق
    }

    // ── طلب النواقص ──

    public function test_the_lawyer_request_asks_only_for_what_is_missing_and_labels_optional(): void
    {
        $this->seed(PermissionSeeder::class);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $ticket = $this->ticketIn('commercial', ['status' => 'قيد التحليل', 'assigned_lawyer_id' => $lawyer->id]);
        TicketDocumentRequirements::recordAiCheck($this->attach($ticket, 'contract.txt'), ['العقد']);

        $this->actingAs($lawyer)->post(route('lawyer.tickets.reqdocs', $ticket))->assertRedirect();

        $body = (string) $ticket->messages()->where('role', 'نواقص')->latest('id')->firstOrFail()->body;
        $this->assertStringContainsString('<span class="doc-chip">الهوية</span>', $body);
        $this->assertStringNotContainsString('<span class="doc-chip">العقد</span>', $body, 'ما ثبت إرفاقه لا يُطلب ثانيةً');
        $this->assertStringContainsString('<span class="doc-chip">المراسلات (اختياريّ)</span>', $body);
        $this->assertLessThan(strpos($body, 'المراسلات'), strpos($body, 'الهوية'), 'الإلزاميّ أوّلاً');
        $this->assertStringContainsString(e(TicketDocumentRequirements::NOTE), $body);
    }

    public function test_nothing_missing_sends_no_empty_list(): void
    {
        $ticket = $this->ticketIn('commercial');
        TicketDocumentRequirements::recordAiCheck($this->attach($ticket, 'all.txt'), ['الهوية', 'العقد', 'المراسلات']);

        $html = TicketDocumentRequirements::requestHtml($ticket, 'نأمل إرفاق:');

        $this->assertStringNotContainsString('doc-chip', $html);
        $this->assertStringContainsString(e(TicketDocumentRequirements::COMPLETE_TEXT), $html);
    }

    // ── الحرّاس ──

    /**
     * المواضع الستّة التي كانت تقرأ `ServiceDocs` تقرأ المصدر الجديد، ولا غلاف شرائح منسوخ فيها.
     */
    public function test_every_former_caller_reads_the_per_ticket_requirements(): void
    {
        $callers = [
            'app/Support/TicketTriage.php' => 4,                         // الترحيب، والمرفقات غير المرتبطة، والمستند غير المرتبط، وحفظ الحصيلة
            'app/Http/Controllers/Employee/TicketController.php' => 2,   // الإحالة بلا مستند، وطلب الموظّف (الغلاف)
            'app/Http/Controllers/Lawyer/TicketController.php' => 1,     // طلب المستشار
            'app/Services/LegalAiService.php' => 1,                      // سياق فحص المستند
        ];

        foreach ($callers as $file => $atLeast) {
            $src = (string) file_get_contents(base_path($file));
            $this->assertGreaterThanOrEqual($atLeast, substr_count($src, 'TicketDocumentRequirements::'), "{$file} لا يقرأ النواقص من مصدرها");
            $this->assertStringNotContainsString('<span class="doc-chip">\'.e(', $src, "{$file} يبني غلاف الشرائح بنفسه");
        }
    }

    /** **لا تعود قائمة مستنداتٍ ثابتة** — لا `ServiceDocs` ولا `REQ_DOCS` ولا بنود القوائم القديمة في الشيفرة. */
    public function test_no_hardcoded_document_list_returns(): void
    {
        // الملفّ لا الصنف: خريطة التحميل المحسَّنة قد تبقى تذكر الملفّ المحذوف حتى `composer dump-autoload`
        $this->assertFileDoesNotExist(app_path('Support/ServiceDocs.php'));

        // بنودٌ مميّزة من القوائم القديمة — مكانها ملفّ الزرع وحده
        $sentinels = ['مسير الرواتب', 'إفادة الإرجاع', 'بيانات المنفّذ ضده', 'شرط/اتفاق التحكيم', 'كشف حساب بنكي', 'الوكالة الشرعية', 'صك حصر الورثة'];
        $patterns = ['/\bServiceDocs\b/', '/\bREQ_DOCS\b/'];

        foreach (array_merge(File::allFiles(app_path()), File::allFiles(resource_path('js'))) as $file) {
            if (! in_array($file->getExtension(), ['php', 'ts', 'tsx'], true)) {
                continue;
            }

            $code = preg_replace('#/\*.*?\*/#s', '', $file->getContents()) ?? '';
            $code = preg_replace('#(^|[^:])//[^\n]*#', '$1', $code) ?? $code;

            foreach ($patterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $code, "{$file->getRelativePathname()} يعيد قائمة مستنداتٍ ثابتة ({$pattern}).");
            }
            // البند نصّاً مستقلّاً بين علامتي اقتباس (عنصر قائمة) — لا جملةً تذكره (بيانات العرض التجريبيّة)
            foreach ($sentinels as $sentinel) {
                $this->assertDoesNotMatchRegularExpression('/[\'"]'.preg_quote($sentinel, '/').'[\'"]/u', $code, "{$file->getRelativePathname()} يكتب بند «{$sentinel}» — مكانه قائمة القسم في الكتالوج.");
            }
        }
    }
}
