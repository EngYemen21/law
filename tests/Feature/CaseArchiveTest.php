<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الأرشفة النهائية للقضية — خطوة إدارية مستقلة بعد الإغلاق تُفعّل حالة «مؤرشفة»
 * (كانت مُعرَّفة لكن لا يضبطها أي انتقال). البنية القائمة تعامل «مؤرشفة» كمغلقة (منع الرسائل).
 */
class CaseArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function caseWithStatus(User $client, string $status): LegalCase
    {
        return LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-7001', 'type' => 'نزاع تجاري',
            'assigned_lawyer' => 'أ. سارة القحطاني', 'status' => $status, 'tone' => 'b-grey', 'update_text' => '—',
        ]);
    }

    public function test_admin_archives_closed_case(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->caseWithStatus($client, 'مغلقة');

        $this->actingAs($admin)->post(route('admin.cases.archive', $case))->assertRedirect();

        $this->assertSame('مؤرشفة', $case->fresh()->status);
        $this->assertTrue($case->messages()->where('role', 'أرشفة')->exists());
        $this->assertDatabaseHas('user_notifications', ['user_id' => $client->id]);
    }

    public function test_archive_blocked_unless_closed(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->caseWithStatus($client, 'صدر الحكم');

        $this->actingAs($admin)->post(route('admin.cases.archive', $case))->assertStatus(422);
        $this->assertSame('صدر الحكم', $case->fresh()->status);
    }

    public function test_client_cannot_message_archived_case(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $client = User::factory()->create(['role' => Role::Client]);
        $case = $this->caseWithStatus($client, 'مغلقة');

        $this->actingAs($admin)->post(route('admin.cases.archive', $case))->assertRedirect();

        // بعد الأرشفة تُقفل المحادثة (سلوك قائم يعامل «مؤرشفة» كمغلقة)
        $this->actingAs($client)->post(route('cases.messages.store', $case), ['body' => 'استفسار'])
            ->assertStatus(422);
    }
}
