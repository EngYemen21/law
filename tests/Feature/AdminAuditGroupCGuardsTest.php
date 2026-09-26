<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiBlindReview;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\JourneyTransition;
use App\Models\LegalDepartment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ai\AiBlindSample;
use App\Services\Ai\AiConfidence;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AiPromptRegistry;
use App\Services\Ai\AiReviewAction;
use App\Services\Ai\AiReviewInbox;
use App\Support\LegalCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * **حرّاس تدقيق لوحة الإدارة (المجموعة ج) — الذكاء والمساعد والمحرّر والمصادر والكتالوج
 * والإعدادات وسجلّ التدقيق وانتقالات الرحلة والتنقّل.**
 *
 * كلّ اختبارٍ هنا يحرس عطلاً قيس فعلاً وعولج عند مصدره، فيُسقطه رجوعُ العطل:
 * أسماءٌ إنجليزيّة تصل المستخدم، تصعيدٌ لا ينجح ولا يصل، مراجعةٌ عمياء على نصٍّ لا يُرى،
 * مسودّةٌ تُحقن من العنوان، تصديرٌ يتجاهل المرشّحات، وقائمة مستنداتٍ تُسقط العامّة بصمت.
 */
class AdminAuditGroupCGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(PermissionSeeder::class);

        return User::factory()->create(['role' => Role::Admin]);
    }

    private function aiRun(array $overrides = []): AiRun
    {
        return AiRun::create(array_merge([
            'task_type' => 'consult',
            'entity_ref' => 'CN-2026-1',
            'source' => AiSource::AiSuccess->value,
            'status' => AiRun::STATUS_NEEDS_REVIEW,
            'model' => 'gemini-2.5-flash',
            'prompt_version' => 'v1',
            'confidence' => 55,
            'confidence_signals' => ['lawyer_from_roster' => true, 'summary_sections_found' => 2, 'summary_sections_expected' => 3],
            'trace_id' => (string) Str::uuid(),
        ], $overrides));
    }

    private static function hasLatin(string $text): bool
    {
        return (bool) preg_match('/[A-Za-z]/', $text);
    }

    // ── الأسماء: مصدرٌ واحد في الخادم، ولا إنجليزيّة تصل المستخدم ──

    public function test_every_registered_task_has_an_arabic_name_including_stored_short_names(): void
    {
        foreach (AiPromptRegistry::PROMPTS as $id => $prompt) {
            $this->assertNotSame('', $prompt['label'] ?? '', "المهمّة «{$id}» بلا اسمٍ عربيّ");
            $this->assertFalse(self::hasLatin($prompt['label']), "اسم «{$id}» يحوي حروفاً لاتينيّة");
        }

        // الأسماء القصيرة المخزَّنة تُردّ إلى معرّف تعليمتها — لا تظهر بلا اسم
        $this->assertSame(AiPromptRegistry::PROMPTS['ticket.triage']['label'], AiPromptRegistry::taskLabel('triage'));
        $this->assertSame(AiPromptRegistry::PROMPTS['consult.analyze']['label'], AiPromptRegistry::taskLabel('consult'));
        $this->assertSame(AiPromptRegistry::PROMPTS['execution.analyze']['label'], AiPromptRegistry::taskLabel('execution'));
        // والمجهول لا يُعرض رمزه
        $this->assertFalse(self::hasLatin(AiPromptRegistry::taskLabel('some.unknown_task')));
    }

    public function test_every_failure_code_has_an_arabic_name(): void
    {
        foreach (AiFailure::codes() as $code) {
            $label = (string) AiFailure::label($code);
            $this->assertNotSame('سبب غير مصنَّف', $label, "رمز التعذّر «{$code}» بلا اسمٍ عربيّ");
            $this->assertFalse(self::hasLatin($label));
        }
    }

    /** كلّ إشارةٍ تكتبها دوالّ الثقة الثلاث لها اسمٌ بجوار تعريفها — وإلّا وصلت الشاشة برمزها. */
    public function test_every_confidence_signal_written_has_an_arabic_name(): void
    {
        $signals = array_merge(
            AiConfidence::forExecution('ملخّص', 2, 1, true, false, true)['signals'],
            AiConfidence::forTicketTriage('قسم', 'تفاصيل', '', 'نوع')['signals'],
            AiConfidence::forConsult('استشارة عمالية', 'الوقائع', 'أ. محمد', ['أ. محمد'], true)['signals'],
        );

        foreach (array_keys($signals) as $key) {
            $this->assertArrayHasKey($key, AiConfidence::SIGNAL_LABELS, "الإشارة «{$key}» بلا اسمٍ عربيّ");
        }

        foreach (AiConfidence::describe($signals) as $row) {
            $this->assertFalse(self::hasLatin($row['label']), "سطر الإشارة «{$row['key']}» يحوي حروفاً لاتينيّة");
        }
    }

    public function test_the_review_screen_receives_arabic_labels_currency_and_escalation_targets(): void
    {
        $admin = $this->admin();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        User::factory()->create(['role' => Role::Client]);
        $this->aiRun(['failure_code' => AiFailure::PROVIDER_ERROR]);

        $this->actingAs($admin)->get(route('admin.ai-review'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.0.taskLabel', AiPromptRegistry::taskLabel('consult'))
                ->where('items.0.failureLabel', AiFailure::label(AiFailure::PROVIDER_ERROR))
                ->where('items.0.signalRows.0.key', 'lawyer_from_roster')
                ->where('currency', 'دولار')
                // المُصعَّد إليهم: المحامي نعم — العميل والمُصعِّد نفسه لا
                ->where('assignees', fn ($list) => collect($list)->pluck('id')->all() === [$lawyer->id]));
    }

    // ── التصعيد: ينجح ويصل ولا يختفي ──

    public function test_escalation_reaches_the_target_inbox_and_notifies_them(): void
    {
        $admin = $this->admin();
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $run = $this->aiRun();

        $this->actingAs($admin)
            ->post(route('admin.ai-review.decide', $run), ['action' => AiReviewAction::Escalate->value, 'escalated_to' => $lawyer->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($lawyer->id, (int) $run->fresh()->escalated_to);
        // كان `whereNull('review_action')` يُخرج المصعَّد من صندوق الجميع — ومنهم المُصعَّد إليه
        $this->assertTrue(AiReviewInbox::forUser($lawyer)->contains('id', $run->id), 'المصعَّد إليه لا يرى ما صُعِّد إليه');
        $this->assertTrue(AiReviewInbox::forUser($admin)->contains('id', $run->id), 'التصعيد يُبقي المراجعة مفتوحة');
        $this->assertTrue(UserNotification::where('user_id', $lawyer->id)->exists(), 'التصعيد لا يُبلَّغ به صاحبه');

        // وقرارٌ يُغلق المراجعة يُخرجه
        $this->actingAs($lawyer)->post(route('lawyer.ai-review.decide', $run), ['action' => AiReviewAction::Accept->value]);
        $this->assertFalse(AiReviewInbox::forUser($admin)->contains('id', $run->id));
    }

    public function test_escalation_to_a_client_or_to_oneself_is_refused(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        $run = $this->aiRun();

        foreach ([$client->id, $admin->id] as $target) {
            $this->actingAs($admin)
                ->post(route('admin.ai-review.decide', $run), ['action' => AiReviewAction::Escalate->value, 'escalated_to' => $target])
                ->assertSessionHasErrors('escalated_to');
        }

        $this->assertNull($run->fresh()->review_action);
    }

    // ── المراجعة العمياء: يُرى النصّ ولا يُرى المصدر ──

    public function test_the_blind_reviewer_sees_the_output_text_but_not_its_source(): void
    {
        $admin = $this->admin();
        $client = User::factory()->create(['role' => Role::Client]);
        $ticket = Ticket::create([
            'user_id' => $client->id, 'number' => 'SB-BLIND-1', 'type' => 'عمالية', 'subject' => 'اختبار',
            'department' => 'القسم العمالي', 'status' => 'قيد التحليل', 'tone' => 'b-blue', 'priority' => 'متوسطة',
        ]);
        $this->aiRun(['task_type' => 'triage', 'entity_ref' => $ticket->number, 'status' => AiRun::STATUS_COMPLETED, 'confidence' => 80]);

        $this->assertSame(1, AiBlindSample::draw($admin, 1));
        $this->assertSame(1, AiBlindReview::count());

        $this->actingAs($admin)->get(route('admin.ai-blind-review'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.0.taskLabel', AiPromptRegistry::taskLabel('triage'))
                ->where('items.0.output', fn ($text) => is_string($text) && str_contains($text, 'SB-BLIND-1'))
                ->where('items.0.machineConfidence', null)
                ->where('items.0.machineStatus', null));
    }

    // ── المساعد ⇦ المحرّر: عبر الجلسة، مهرَّباً، ولا مسودّة من العنوان ──

    public function test_the_assistant_hands_the_draft_to_the_editor_through_the_session_escaped(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/assistant/to-editor', ['draft' => "<img src=x onerror=alert(1)>\n\nالفقرة الثانية", 'title' => 'مذكرة رد'])
            ->assertRedirect('/admin/editor/create');

        $this->actingAs($admin)->get('/admin/editor/create')
            ->assertInertia(fn (Assert $page) => $page
                ->where('incomingTitle', 'مذكرة رد')
                ->where('incomingDraft', fn ($html) => str_contains($html, '&lt;img') && ! str_contains($html, '<img') && str_contains($html, 'الفقرة الثانية')));

        // رسالةٌ لمرّةٍ واحدة — ولا مصدر للمسودّة الحرّة من العنوان إطلاقاً
        $this->actingAs($admin)->get('/admin/editor/create?draft='.urlencode('<b onclick=x>حقن</b>'))
            ->assertInertia(fn (Assert $page) => $page->where('incomingDraft', ''));
    }

    // ── التصدير بمرشّحات الشاشة ──

    public function test_the_audit_export_applies_the_screen_filters_without_a_silent_cap(): void
    {
        $admin = $this->admin();
        AuditLog::record('قيد مالي', 'وصف مالي', 'مالية وفواتير', user: $admin);
        AuditLog::record('قيد أمني', 'وصف أمني', 'أمن وحماية', 'critical', user: $admin);

        $csv = $this->actingAs($admin)->get(route('admin.audit-logs.export', ['category' => 'مالية وفواتير']))->streamedContent();

        $this->assertStringContainsString('قيد مالي', $csv);
        $this->assertStringNotContainsString('قيد أمني', $csv);
        // الأهمية والدور بأسمائهما لا برموزهما
        $this->assertStringContainsString(AuditLog::SEVERITY_LABELS['info'], $csv);
        $this->assertStringContainsString(Role::Admin->label(), $csv);
    }

    public function test_the_journey_export_applies_the_transition_and_actor_filters(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['role' => Role::Lawyer]);

        foreach ([['ticket.opened', $admin->id, 'SB-J-1'], ['ticket.scheduled', $admin->id, 'SB-J-2'], ['ticket.opened', $other->id, 'SB-J-3']] as [$transition, $actor, $ref]) {
            JourneyTransition::create([
                'entity_type' => 'Ticket', 'entity_id' => 1, 'entity_ref' => $ref, 'transition' => $transition,
                'from_state' => null, 'to_state' => 'جديدة', 'actor_id' => $actor,
            ]);
        }

        $csv = $this->actingAs($admin)
            ->get('/admin/journey-transitions/export?transition=ticket.opened&actor_id='.$admin->id)
            ->streamedContent();

        $this->assertStringContainsString('SB-J-1', $csv);
        $this->assertStringNotContainsString('SB-J-2', $csv, 'مرشّح الانتقال مُهمَل');
        $this->assertStringNotContainsString('SB-J-3', $csv, 'مرشّح الفاعل مُهمَل');
    }

    // ── الكتالوج: أوّل مستندٍ لا يُسقط القائمة العامّة ──

    public function test_the_first_custom_document_keeps_the_general_list_as_editable_rows(): void
    {
        $admin = $this->admin();
        $department = LegalDepartment::query()->firstOrFail();
        $department->documents()->delete();

        $this->actingAs($admin)
            ->post(route('admin.catalogue.documents.store', $department), ['name' => 'عقد العمل', 'required' => true])
            ->assertSessionHasNoErrors();

        $names = $department->documents()->pluck('name')->all();

        foreach (LegalCatalogue::DEFAULT_DOCUMENTS as $default) {
            $this->assertContains($default['name'], $names, "سقط «{$default['name']}» من القسم بإضافة أوّل بند");
        }
        $this->assertContains('عقد العمل', $names);
    }

    // ── شارات الإدارة من مصادر عدّها ──

    public function test_admin_nav_badges_come_from_the_review_inbox(): void
    {
        $admin = $this->admin();
        $this->aiRun();

        $this->actingAs($admin)->get(route('admin.ai-review'))
            ->assertInertia(fn (Assert $page) => $page->where('navBadges./admin/ai-review', 1));
    }

    public function test_older_notifications_page_through_the_same_feed(): void
    {
        $admin = $this->admin();
        foreach (range(1, 20) as $i) {
            UserNotification::create(['user_id' => $admin->id, 'icon' => 'info', 'tone' => 't-blue', 'body' => "إشعار {$i}", 'time_label' => 'الآن', 'is_read' => false]);
        }

        $first = $this->actingAs($admin)->getJson('/notifications/more')->assertOk()->json();
        $this->assertCount(15, $first['items']);
        $this->assertTrue($first['hasMore']);

        $next = $this->actingAs($admin)->getJson('/notifications/more?before='.end($first['items'])['id'])->json();
        $this->assertCount(5, $next['items']);
        $this->assertFalse($next['hasMore']);
    }

    // ── حارس المسح: ما أُزيل من الواجهة لا يعود ──

    public function test_client_side_label_maps_and_status_string_comparisons_do_not_return(): void
    {
        $read = fn (string $path) => file_get_contents(resource_path($path));

        $review = $read('js/pages/admin/ai-review.tsx');
        $this->assertStringNotContainsString('formatSignalLabel', $review);
        $this->assertStringNotContainsString('formatTaskTypeLabel', $review);
        $this->assertStringNotContainsString('ر.س', $review, 'عملة الكلفة من الخادم لا منقوشة');

        $ops = $read('js/pages/admin/ai-ops.tsx');
        $this->assertStringNotContainsString('`$${', $ops, 'عملة الكلفة من الخادم لا «$» منقوشة');

        $sources = $read('js/pages/admin/legal-sources.tsx');
        $this->assertDoesNotMatchRegularExpression("/===\\s*'(معتمد|مسودة|موقوف)'|!==\\s*'(معتمد|مسودة|موقوف)'/u", $sources);

        $this->assertStringNotContainsString('?draft=', $read('js/pages/lawyer/assistant.tsx'));

        $audit = $read('js/pages/admin/audit-logs.tsx');
        $this->assertStringNotContainsString('toUpperCase', $audit);
        $this->assertStringNotContainsString('Audit Trail', $audit);
        $this->assertDoesNotMatchRegularExpression("/case\\s*'[\\x{0600}-\\x{06FF}]/u", $audit, 'مقارنة فئات عربيّة في الواجهة');

        $this->assertStringNotContainsString('href="/notifications"', $read('js/components/navigation/NotificationDropdown.tsx'));
    }
}
