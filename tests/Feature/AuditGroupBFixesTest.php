<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\JourneyTransition;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **المجموعة (ب) من تدقيق P4** (قرار المالك 2026-09-30): حدٌّ لنداءات الذكاء المدفوعة (٥ كلّ ١٠ دقائق)،
 * وروابط المحرّر لمن يملكه، وشارة التحويل من الخادم لا من نصّ الحالة، وقفلٌ ورسالة رفضٍ لأفعالٍ كانت بلا
 * أيّهما، ورابط سجلّ الانتقالات لا يشير إلى ملفٍّ محذوف.
 */
class AuditGroupBFixesTest extends TestCase
{
    use RefreshDatabase;

    /** كلّ زرٍّ يطلق نداء ذكاءٍ عند الطلب — في اللوحات الثلاث. */
    private const AI_ROUTES = [
        'admin.ai-ops.evaluate', 'admin.assistant.generate', 'admin.consults.analyze', 'admin.editor.ai-assist', 'admin.summary.najiz', 'admin.summary.rerun',
        'employee.consults.analyze', 'employee.editor.ai-assist', 'employee.tickets.rerun',
        'lawyer.assistant.generate', 'lawyer.consults.analyze', 'lawyer.editor.ai-assist', 'lawyer.summary.najiz', 'lawyer.summary.rerun',
    ];

    public function test_every_on_demand_ai_route_carries_the_limit(): void
    {
        foreach (self::AI_ROUTES as $name) {
            $this->assertContains('throttle:ai-calls', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_five_calls_per_ten_minutes_per_user_and_action(): void
    {
        $this->seed(PermissionSeeder::class);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $colleague = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        foreach ([$lawyer, $colleague] as $u) {
            $u->syncPermissions(Permission::whereIn('name', [Permissions::LEGAL_ASSISTANT])->get());
        }

        // جسمٌ ناقص: يُردّ بالتحقّق (٤٢٢) بلا نداءٍ فعليّ — والحدّ يُحتسب قبله
        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [])->assertStatus(422);
        }
        $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [])->assertStatus(429)
            ->assertJsonPath('message', 'محاولاتٌ كثيرة في وقتٍ قصير — انتظر قليلاً ثمّ أعد المحاولة.');

        // الحدّ لكلّ مستخدمٍ ولكلّ فعل
        $this->actingAs($colleague)->postJson('/lawyer/assistant/generate', [])->assertStatus(422);
        $this->actingAs($lawyer)->postJson('/lawyer/editor/ai-assist', [])->assertStatus(422);

        $this->travel(11)->minutes();
        $this->actingAs($lawyer)->postJson('/lawyer/assistant/generate', [])->assertStatus(422);
    }

    public function test_lawyer_pages_read_server_flags_and_lock_their_actions(): void
    {
        $js = fn (string $p) => (string) file_get_contents(resource_path("js/pages/{$p}"));

        // روابط المحرّر لمن يملك «المساعد القانوني»
        foreach (['lawyer/summary.tsx', 'lawyer/summaries.tsx'] as $p) {
            $this->assertStringContainsString("useCan()('المساعد القانوني')", $js($p), $p);
        }

        // شارة التحويل بنوعه، ولا نصّ حالةٍ عربيّ في الشرط
        $tickets = $js('lawyer/tickets.tsx');
        $this->assertStringNotContainsString("t.status === 'مكتملة'", $tickets);
        $this->assertStringContainsString("t.statusCode === 'Completed'", $tickets);
        $this->assertStringContainsString("t.convertedType === 'execution'", $tickets);

        // أفعالٌ بقفلٍ ورسالة رفض
        $this->assertStringContainsString('useServerAction', $js('lawyer/tasks.tsx'));
        $this->assertStringContainsString('useServerAction', $js('lawyer/ticketchat.tsx'));
        $this->assertStringContainsString('serverMessage(', $js('lawyer/case.tsx'));
        $this->assertMatchesRegularExpression('/reviewDoc[\s\S]{0,400}fallback/', $js('execflow.tsx'));
    }

    /** ملفٌّ حُذف: السطر يبقى في السجلّ بلا رابطٍ يقود إلى ٤٠٤. */
    public function test_the_journey_log_does_not_link_a_deleted_entity(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $alive = Ticket::create(['user_id' => $client->id, 'number' => 'SB-GB-1', 'type' => 'نزاع', 'status' => 'جديدة', 'tone' => 'b-blue']);
        foreach ([[$alive->id, 'SB-GB-1'], [99999, 'SB-GB-GONE']] as [$id, $ref]) {
            JourneyTransition::create(['entity_type' => 'Ticket', 'entity_id' => $id, 'entity_ref' => $ref, 'transition' => 'ticket.test', 'to_state' => 'جديدة']);
        }

        $this->actingAs($admin)->get('/admin/journey-transitions')->assertInertia(fn ($page) => $page
            ->where('transitions.data', fn ($rows) => collect($rows)->firstWhere('entityRef', 'SB-GB-1')['url'] === '/admin/tickets/SB-GB-1'
                && collect($rows)->firstWhere('entityRef', 'SB-GB-GONE')['url'] === null));
    }
}
