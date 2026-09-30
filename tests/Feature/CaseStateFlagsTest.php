<?php

namespace Tests\Feature;

use App\Domain\Journey\Enums\CaseStatus;
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

    /**
     * تدقيق 2026-09-29: صفحتا القضيّة للمحامي والموظّف كانتا تقارنان «مؤرشفة»/«منظورة» نصّاً، وتُظهران صندوق
     * الردّ على قضيّةٍ مؤرشفة يردّه الخادم — فصار لهما علمان من التعداد.
     */
    public function test_archived_and_in_court_flags_follow_the_status_enum(): void
    {
        $flags = fn (CaseStatus $s) => (new LegalCase(['status' => $s->value]))->stateFlags();

        $this->assertTrue($flags(CaseStatus::Archived)['isArchived']);
        $this->assertFalse($flags(CaseStatus::Closed)['isArchived']);
        $this->assertTrue($flags(CaseStatus::InCourt)['inCourt']);
        $this->assertFalse($flags(CaseStatus::Judged)['inCourt']);
    }

    public function test_case_pages_use_flags_not_status_text(): void
    {
        foreach (['lawyer/case', 'employee/case'] as $page) {
            $src = (string) file_get_contents(resource_path("js/pages/{$page}.tsx"));
            $this->assertStringNotContainsString("'مؤرشفة'", $src, $page);
            $this->assertStringNotContainsString("'منظورة'", $src, $page);
            $this->assertStringContainsString('live.isArchived ? (', $src, "{$page}: صندوق الردّ يُستبدل للمؤرشفة");
        }
    }
}
