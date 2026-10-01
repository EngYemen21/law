<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ZoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **لا نصّ حالةٍ عربيّ في منطق وحدة الاجتماعات** (قرار المالك 2026-10-01 · CLAUDE.md).
 *
 * كانت المقارنات تكتب النصّ نفسه في مواضع متفرّقة: `approve === 'معتمد'` (المتحكّم والملخّص)،
 * `conf === 'سري'` (الإنشاء والشارتان في الواجهة)، `'status' => 'قادم'` و`'منجزة'`. النصّ يبقى
 * بياناً في قاعدة البيانات؛ والمقارنة من موضعٍ واحد: `Meeting::APPROVED` / `isApproved()`،
 * `Meeting::CONF_SECRET` / `isConfidential()`، `MeetingStatus::Upcoming`، `Task::DONE`،
 * والواجهة تقرأ علَمَي `approved` و`confidential` من الخادم.
 */
class MeetingNoArabicLogicTest extends TestCase
{
    use RefreshDatabase;

    /** الشيفرة بلا تعليقات — التعليق يشرح التاريخ فيذكر النصّ القديم. */
    private static function phpCode(string $path): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(base_path($path))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    // ————— حارسٌ نصّيّ —————

    public function test_meeting_logic_has_no_arabic_status_comparisons(): void
    {
        $violations = [];

        foreach (['app/Http/Controllers/Staff/MeetingController.php', 'app/Support/MeetingSummary.php'] as $path) {
            $code = self::phpCode($path);
            if (preg_match("/(===|!==|==|!=)\\s*'معتمد'|'معتمد'\\s*(===|!==|==|!=)/u", $code)) {
                $violations[] = "{$path}: مقارنةٌ بـ'معتمد' — استعمل \$meeting->isApproved()";
            }
            if (preg_match("/'approve'\\s*=>\\s*'معتمد'/u", $code)) {
                $violations[] = "{$path}: كتابة 'معتمد' حرفيّاً — استعمل Meeting::APPROVED";
            }
            if (preg_match("/->where\\('status',\\s*'منجزة'\\)/u", $code)) {
                $violations[] = "{$path}: where('status','منجزة') — استعمل Task::DONE";
            }
        }

        // `'سري'` لا يُقارَن في الخادم إلا عبر الثابت
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $path = substr($file->getPathname(), strlen(base_path()) + 1);
            if (preg_match("/(===|!==)\\s*'سري'/u", self::phpCode($path))) {
                $violations[] = "{$path}: === 'سري' — استعمل Meeting::isConfidential()/isConfidentialLevel()";
            }
        }

        if (preg_match("/'status'\\s*=>\\s*'قادم'/u", self::phpCode('app/Support/MeetInvitation.php'))) {
            $violations[] = "app/Support/MeetInvitation.php: 'status' => 'قادم' — استعمل MeetingStatus::Upcoming->value";
        }

        // الواجهة: البطاقة والبثّ يحملان علَمَين — لا مقارنة بنصّ «معتمد/قادم/جارٍ/سري» على بيانات الخادم
        foreach (['resources/js/lib/meeting-ui.tsx', 'resources/js/pages/meetings.tsx', 'resources/js/pages/admin/meetmgmt.tsx'] as $path) {
            $code = (string) file_get_contents(base_path($path));
            if (preg_match("/\\.(conf|approve|status)\\s*===\\s*'(سري|معتمد|قادم|جارٍ)'/u", $code, $m)) {
                $violations[] = "{$path}: {$m[0]} — اقرأ العلَم الخادميّ (confidential/approved/up)";
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    // ————— السلوك محفوظ —————

    public function test_approved_meeting_summary_is_still_refused(): void
    {
        $meeting = Meeting::create([
            'ref' => 'M-NAL-1', 'title' => 'اجتماع', 'when_label' => 'أمس · 11:00', 'status' => 'منتهٍ',
            'approve' => Meeting::APPROVED, 'sum_approved' => true, 'summary' => 'الملخص المعتمد.',
        ]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->assertTrue($meeting->isApproved());
        $this->actingAs($employee)
            ->post("/employee/meetings/{$meeting->id}/summary", ['summary' => 'بديل'])
            ->assertStatus(422);
        $this->assertSame('الملخص المعتمد.', $meeting->fresh()->summary);
    }

    public function test_full_card_carries_the_confidential_flag(): void
    {
        $secret = Meeting::create(['ref' => 'M-NAL-2', 'title' => 'سرّيّ', 'when_label' => 'غداً · 10:00', 'status' => 'قادم', 'conf' => Meeting::CONF_SECRET]);
        $normal = Meeting::create(['ref' => 'M-NAL-3', 'title' => 'عاديّ', 'when_label' => 'غداً · 10:00', 'status' => 'قادم', 'conf' => 'عادي']);

        $this->assertTrue($secret->toFullCard()['confidential']);
        $this->assertFalse($normal->toFullCard()['confidential']);
        $this->assertFalse(Meeting::isConfidentialLevel(null));
    }

    public function test_confidential_level_reaches_zoom_on_create(): void
    {
        $this->mock(ZoomService::class, function ($mock) {
            $mock->shouldReceive('createMeeting')->once()
                ->withArgs(fn ($topic, $minutes, $confidential) => $confidential === true)
                ->andReturn(null);
        });
        $admin = User::factory()->create(['role' => Role::Admin, 'status' => 'active']);

        $this->actingAs($admin)->post(route('admin.meetings.store'), [
            'title' => 'اجتماع سرّيّ', 'type' => 'اجتماع داخلي', 'conf' => Meeting::CONF_SECRET,
            'day' => now()->addDays(2)->toDateString(), 'time' => '10:00',
        ])->assertRedirect();

        $this->assertTrue(Meeting::firstOrFail()->isConfidential());
    }
}
