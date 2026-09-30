<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CaseMessage;
use App\Models\LegalCase;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ردّ المحامي في محادثة القضيّة يُنسب إلى حسابه ولا يأخذ المحادثة (قرار المالك 2026-09-25: المحامي له
 * إسناده المستقلّ). تدقيق 2026-09-29: مسار ردّ المحامي بلا وسيط `conversation.reply` — وهذا صحيحٌ لا عطل.
 */
class LawyerCaseReplySenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_lawyer_case_reply_is_linked_to_the_lawyer(): void
    {
        $this->seed(PermissionSeeder::class);
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'status' => 'active']);
        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-SND-1', 'title' => 'دعوى', 'type' => 'تجاري', 'status' => 'منظورة',
            'tone' => 'b-blue', 'update_text' => '—', 'assigned_lawyer_id' => $lawyer->id, 'assigned_lawyer' => $lawyer->name,
        ]);

        $this->actingAs($lawyer)->post(route('lawyer.cases.reply', $case), ['body' => 'ردّ المحامي'])->assertSuccessful();

        $this->assertSame($lawyer->id, CaseMessage::where('body', 'like', '%ردّ المحامي%')->value('sender_id'));
        $this->assertNull($case->fresh()->handler_id, 'المحامي له إسناده المستقلّ — لا يأخذ المحادثة');
    }
}
