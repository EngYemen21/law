<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LegalCase;
use App\Models\User;
use App\Support\CaseJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **أعلام حالة القضيّة من الخادم** (`LegalCase::stateFlags`) — كانت أربع صفحاتٍ تنسخ
 * `['مغلقة','مؤرشفة']` و`['صدر الحكم','مغلقة']` لتقرّر ما يُفتح (المحادثة · الجلسات · تصحيح الحكم).
 */
class CaseStateFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_flags_follow_the_server_groups_for_every_status(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        foreach (array_keys(CaseJourney::STATUSES) as $status) {
            $card = LegalCase::create([
                'user_id' => $client->id, 'number' => 'CASE-FLG-'.uniqid(), 'type' => 'نزاع', 'status' => $status, 'update_text' => '—',
            ])->toCard();

            $this->assertSame(! in_array($status, CaseJourney::CLOSED, true), $card['isActive'], $status);
            $this->assertSame(in_array($status, CaseJourney::POST_JUDGMENT, true), $card['postJudgment'], $status);
        }
    }

    public function test_no_case_page_keeps_its_own_status_lists(): void
    {
        foreach (['pages/employee/cases.tsx', 'pages/employee/case.tsx', 'pages/lawyer/case.tsx', 'pages/casechat.tsx'] as $page) {
            $src = (string) file_get_contents(resource_path("js/{$page}"));
            $this->assertStringNotContainsString("['مغلقة', 'مؤرشفة']", $src, $page);
            $this->assertStringNotContainsString("['صدر الحكم', 'مغلقة']", $src, $page);
        }
    }
}
