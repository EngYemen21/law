<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * روابط الانتقال من التذكرة إلى الملفّ الناتج عنها — تدقيق 2026-09-29: كان رابط «الانتقال لملف التنفيذ»
 * للعميل `/execs/{no}` ولا مسار كهذا (404)؛ صفحة التنفيذ تفتح الملفّ بـ`?id=` رقمه.
 */
class AuditDeepLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_execution_deep_link_opens_the_file(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create([
            'user_id' => $client->id, 'number' => 'EXEC-AUD-1', 'subject' => 'تنفيذ سند', 'status' => 'قيد التنفيذ', 'stage' => 8,
        ]);

        $this->actingAs($client)->get('/execs/'.$exec->number)->assertNotFound();

        $this->actingAs($client)->get('/execs?id='.$exec->number)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('execflow')->where('initialId', $exec->number));
    }

    public function test_ticket_chat_links_to_the_execution_with_the_query_form(): void
    {
        $source = (string) file_get_contents(resource_path('js/pages/ticketchat.tsx'));

        $this->assertStringNotContainsString('`/execs/${', $source, 'لا مسار /execs/{no} — يعطي 404');
        $this->assertStringContainsString('/execs?id=${encodeURIComponent(ticket.executionNumber)}', $source);
    }

    /** اختصارات لوحة المحامي `/lawyer/assistant?action=…` تفتح إجراءها — كانت المعلمة لا يقرؤها أحد. */
    public function test_assistant_reads_the_quick_action_from_the_link(): void
    {
        $dashboard = (string) file_get_contents(resource_path('js/pages/lawyer/dashboard.tsx'));
        $assistant = (string) file_get_contents(resource_path('js/pages/lawyer/assistant.tsx'));

        preg_match_all('/assistant\\?action=([a-z_]+)/', $dashboard, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $action) {
            $this->assertStringContainsString("id: '{$action}'", $assistant, "إجراء {$action} معرَّفٌ في المساعد");
        }
        $this->assertStringContainsString(".get('action')", $assistant, 'المساعد يقرأ المعلمة');
    }
}
