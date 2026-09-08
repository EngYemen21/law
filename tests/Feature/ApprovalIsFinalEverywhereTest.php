<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\MeetRequest;
use App\Models\User;
use App\Services\ZoomService;
use App\Support\ConsultSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **الاعتماد نهائيّ — في الاستشارات كما في الاجتماعات.**
 *
 * طُبِّقت القاعدة على الاجتماعات أوّلاً (`MeetingApprovalLockTest`)، وبقيت الاستشارات
 * على الحارس القالبيّ وحده: و`approveSummary` يشترط `filled($summary)` ولا يشترط ألّا
 * يكون قالبياً — فنصٌّ قالبيٌّ اعتُمد ووصل العميل كان يبقى قابلاً للاستبدال صامتاً،
 * والقرارات تُقترح بعد الاعتماد فتصل العميل بما لم تعتمده الإدارة.
 *
 * ومعها صدقُ إلغاء دعوة الاجتماع: كان يُرجع رسالة نجاحٍ ولو لم يقع إلغاء.
 */
class ApprovalIsFinalEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private function approvedConsult(): Consult
    {
        $client = User::factory()->create(['role' => Role::Client]);

        return Consult::create([
            'user_id' => $client->id,
            'ref' => 'CN-FIN-'.random_int(100, 999),
            'subject' => 'نزاع تجاري',
            'type' => 'استشارة',
            'channel' => 'مرئية',
            'status' => 'منتهية',
            'session' => 'منتهية',
            'tone' => 'b-grey',
            'lawyer' => 'مستشار',
            'meet_id' => '9'.random_int(10 ** 9, 10 ** 10 - 1),
            'summary' => 'الملخّص المعتمد الذي وصل العميل.',
            'summary_approved_at' => now(),
            'decisions' => [],
        ]);
    }

    public function test_an_automatic_zoom_pull_does_not_touch_an_approved_consult(): void
    {
        config(['services.glm.key' => 'test-key']);
        Http::fake();

        $consult = $this->approvedConsult();

        ConsultSummary::pull($consult, app(ZoomService::class), [
            'summary_overview' => 'نصٌّ وارد من Zoom بعد الاعتماد.',
            'next_steps' => ['خطوة مقترَحة'],
        ]);

        $consult->refresh();
        $this->assertSame('الملخّص المعتمد الذي وصل العميل.', $consult->summary);
        $this->assertSame([], $consult->decisions ?? []);
        // ولا نداءَ ذكاءٍ لاستخلاص قرارات على معتمد
        Http::assertNothingSent();

        // والوقائع تبقى تُحدَّث — zoom_summary ليس ممّا شهدت به الإدارة
        $this->assertNotNull($consult->zoom_summary_at);
    }

    public function test_the_manual_zoom_sync_button_is_refused_after_approval(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $consult = $this->approvedConsult();

        $this->actingAs($admin)
            ->post("/admin/consults/{$consult->id}/zoom-sync")
            ->assertStatus(422);

        $this->assertSame('الملخّص المعتمد الذي وصل العميل.', $consult->fresh()->summary);
    }

    // ————— إلغاء الدعوة: لا رسالةَ نجاحٍ بلا فعل —————

    public function test_cancelling_an_executed_invitation_is_refused_not_silently_ignored(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $req = MeetRequest::create([
            'user_id' => $client->id,
            'ref' => 'MR-FIN-001',
            'service' => 'استشارة',
            'type' => 'استشارة مرئية',
            'day' => now()->addDay()->format('Y-m-d'),
            'time' => '11:00',
            'duration_min' => 60,
            'sent_by' => $employee->name,
            'sent_by_id' => $employee->id,
            'stage' => MeetRequest::STAGE_EXECUTED,
        ]);

        $this->actingAs($employee)
            ->post("/employee/meetreqs/{$req->id}/cancel")
            ->assertStatus(422);

        // **الحالة لم تتغيّر** — وكانت الشاشة تقول «تم إلغاء الدعوة»
        $this->assertSame(MeetRequest::STAGE_EXECUTED, $req->fresh()->stage);
    }

    public function test_cancelling_a_pending_invitation_still_works(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $req = MeetRequest::create([
            'user_id' => $client->id, 'ref' => 'MR-FIN-002',
            'service' => 'استشارة', 'type' => 'استشارة مرئية',
            'day' => now()->addDay()->format('Y-m-d'), 'time' => '11:00',
            'duration_min' => 60, 'sent_by' => $employee->name,
            'sent_by_id' => $employee->id, 'stage' => MeetRequest::STAGE_SENT,
        ]);

        $this->actingAs($employee)
            ->post("/employee/meetreqs/{$req->id}/cancel")
            ->assertRedirect();

        $this->assertSame(MeetRequest::STAGE_CANCELLED, $req->fresh()->stage);
    }

    // ————— الشاشات —————

    public function test_the_client_reads_approval_instead_of_inferring_it(): void
    {
        $ui = file_get_contents(resource_path('js/pages/meetings.tsx'));

        $this->assertStringNotContainsString('approved: !!(e.minutes || e.summary || x.approved)', $ui);
        $this->assertStringContainsString(
            "approved: e.approve !== undefined ? e.approve === 'معتمد' : x.approved,",
            $ui
        );
    }

    public function test_the_admin_zoom_tab_shows_the_session_material_it_holds(): void
    {
        $ui = file_get_contents(resource_path('js/pages/admin/consults.tsx'));

        // كان التبويب يعرض التسجيل والصوت فقط ويُغفل المادّة نفسها
        $this->assertStringContainsString('<RichText text={drawerConsult.zoomSummary} />', $ui);
        $this->assertStringContainsString('drawerConsult.zoomParticipantsLog.map(', $ui);
        $this->assertStringContainsString('drawerConsult.zoomAiNextSteps.map(', $ui);
    }

    /**
     * **مسحٌ شاملٌ لا قائمةٌ مكتوبةٌ بيد.**
     *
     * أوّلَ مرّة غطّيتُ مسارَي حجزٍ من أربعة وظننتُ الأمرَ تامّاً، فبقي مسارا الإدارة
     * في التنظيم (إنشاء الاجتماع وإنشاء الاستشارة) مكشوفَين — وهما الأعرضُ تعرّضاً.
     * فالحارس يمسح **كلّ** نداءٍ لـ`createMeeting(` في `app/` ويشترط رفعَ المهلة في
     * الدالّة الحاوية له، كي لا يُنسى مسارٌ جديدٌ يُضاف غداً.
     */
    public function test_every_path_that_creates_a_zoom_session_raises_the_web_time_limit(): void
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        $checked = 0;
        foreach ($files as $file) {
            $src = file_get_contents($file);
            // تعريفُ الخدمة نفسه ليس مساراً
            if (str_contains($src, 'public function createMeeting(')) {
                continue;
            }
            if (! str_contains($src, 'createMeeting(')) {
                continue;
            }

            // الدوالّ الحاوية للنداء: من رأس الدالّة حتى رأس التالية
            $parts = preg_split('/(?=
    (?:public|protected|private) )/u', $src);
            foreach ($parts as $part) {
                if (! str_contains($part, 'createMeeting(')) {
                    continue;
                }
                $checked++;
                preg_match('/function (\w+)/u', $part, $name);
                $this->assertStringContainsString(
                    'WebTimeLimit::raise(',
                    $part,
                    basename($file).'::'.($name[1] ?? '?')
                    .' يُنشئ جلسة Zoom داخل الطلب بلا رفع مهلة الويب — تُبلَغ الثلاثون '
                    .'فيرى المستخدم خطأً والسجلّ كُتب فعلاً.'
                );
            }
        }

        // فحصٌ للفحص: لو تغيّر اسمُ النداء يوماً لَما مسح شيئاً ومرّ صامتاً
        $this->assertGreaterThanOrEqual(4, $checked, 'المسح لم يجد مسارات Zoom — تحقّق من صيغة النداء');
    }
}
