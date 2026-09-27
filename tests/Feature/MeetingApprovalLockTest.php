<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ZoomService;
use App\Support\MeetingSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ثلاثةُ عيوبٍ من عائلةٍ واحدة في وحدة الاجتماعات: **النظام يشهد بما لم يقع**.
 *
 * ١) اعتمادٌ يشهد لنصٍّ لم يعد موجوداً: saveSummary/saveMinutes كانتا بلا حارسِ اعتماد،
 *    والشاشة تعرض «✓ معتمد — ومُرسل للعميل» والحقل قابلٌ للتحرير. فيُحفظ نصٌّ جديد ويبقى
 *    approve='معتمد' ويرسله toCard للعميل، فتشهد الإدارةُ لنصٍّ لم تره. والاعتماد نهائيّ
 *    بقرار صاحب المنتج؛ و«حفظ» مسوّدةٌ لا اعتماد.
 * ٢) «حضور 0%»: العمود attend لم يُكتب من Zoom قطّ، وسجلّ Zoom المفصّل مخزّنٌ ولا يقرؤه
 *    أحد. الصفر كان يُقرأ «لم يحضر أحد» والحقيقة «لم يُقَس».
 * ٣) عدُّ التقارير على الحالة المخزّنة والشاشات تعرض المشتقّة — و«لم ينعقد» لا يكتبها إلا
 *    أمرٌ مجدول، فبطاقاتٌ تظهر بها وعدّادُها صفر.
 */
class MeetingApprovalLockTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): User
    {
        return User::factory()->create(['role' => Role::Employee]);
    }

    private function meeting(array $extra = []): Meeting
    {
        return Meeting::create(array_merge([
            'ref' => 'M-LOCK-'.uniqid(),
            'title' => 'اجتماع متابعة العقد',
            'when_label' => 'أمس · 11:00',
            'status' => 'منتهٍ',
        ], $extra));
    }

    // ————— ١ · الاعتماد نهائيّ —————

    public function test_approved_summary_and_minutes_cannot_be_rewritten(): void
    {
        $meeting = $this->meeting([
            'approve' => 'معتمد', 'sum_approved' => true,
            'summary' => 'الملخص المعتمد الذي وصل العميل.',
            'minutes' => 'المحضر المعتمد الذي وصل العميل.',
        ]);
        $employee = $this->employee();

        $this->actingAs($employee)
            ->post("/employee/meetings/{$meeting->id}/summary", ['summary' => 'نصّ بديل لم تره الإدارة'])
            ->assertStatus(422);

        $this->actingAs($employee)
            ->post("/employee/meetings/{$meeting->id}/minutes", ['minutes' => 'محضر بديل لم تره الإدارة'])
            ->assertStatus(422);

        // **الفحص على المحتوى لا على رمز الاستجابة**: ٤٢٢ بلا حفظٍ فعليّ هو المطلوب
        $meeting->refresh();
        $this->assertSame('الملخص المعتمد الذي وصل العميل.', $meeting->summary);
        $this->assertSame('المحضر المعتمد الذي وصل العميل.', $meeting->minutes);
    }

    public function test_draft_before_approval_is_saved_and_still_editable(): void
    {
        $meeting = $this->meeting();
        $employee = $this->employee();

        $this->actingAs($employee)
            ->post("/employee/meetings/{$meeting->id}/summary", ['summary' => 'مسودّة أولى'])
            ->assertRedirect();
        $this->assertSame('مسودّة أولى', $meeting->fresh()->summary);

        // الحفظ ليس اعتماداً: النصّ يبقى قابلاً للتعديل ما لم تعتمده الإدارة
        $this->actingAs($employee)
            ->post("/employee/meetings/{$meeting->id}/summary", ['summary' => 'مسودّة ثانية'])
            ->assertRedirect();
        $this->assertSame('مسودّة ثانية', $meeting->fresh()->summary);
        $this->assertNotSame('معتمد', $meeting->fresh()->approve);
    }

    public function test_approved_minutes_are_locked_for_the_assigned_lawyer_too(): void
    {
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $meeting = $this->meeting([
            'approve' => 'معتمد', 'sum_approved' => true,
            'assigned_lawyer_id' => $lawyer->id,
            'minutes' => 'محضر معتمد',
        ]);

        $this->actingAs($lawyer)
            ->post("/lawyer/meetings/{$meeting->id}/minutes", ['minutes' => 'تعديل بعد الاعتماد'])
            ->assertStatus(422);

        $this->assertSame('محضر معتمد', $meeting->fresh()->minutes);
    }

    // ————— ٢ · الحضور من Zoom، وما لم يُقَس لا يُعرض —————

    public function test_attendance_is_null_when_zoom_never_reported(): void
    {
        $meeting = $this->meeting(['attend' => 0]);

        $this->assertNull($meeting->attendedCount());
        $this->assertNull($meeting->presenceRate());

        $card = $meeting->toFullCard();
        $this->assertNull($card['attendedCount']);
        $this->assertNull($card['presenceRate']);
    }

    public function test_attendance_counts_unique_joiners_from_the_zoom_log(): void
    {
        $client = User::factory()->create(['role' => Role::Client, 'name' => 'شركة الأفق']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer, 'name' => 'سارة القحطاني']);

        $meeting = $this->meeting([
            'user_id' => $client->id,
            'assigned_lawyer_id' => $lawyer->id,
            'participants' => 'خالد العتيبي (الإدارة القانونية)، منى الزهراني',
            'duration_sec' => 3600,
            'zoom_participants_log' => [
                // الشخص نفسه بصفّين — انقطعت الشبكة فأعاد الدخول: يُعدّ واحداً وتُجمع مدّته
                ['name' => 'سارة القحطاني', 'email' => 's@office.sa', 'join_time' => '2026-09-01 11:00:00', 'duration_sec' => 1800],
                ['name' => 'سارة القحطاني', 'email' => 's@office.sa', 'join_time' => '2026-09-01 11:35:00', 'duration_sec' => 1800],
                ['name' => 'شركة الأفق', 'email' => 'c@ufuq.sa', 'join_time' => '2026-09-01 11:05:00', 'duration_sec' => 1800],
                // مدعوٌّ ظهر في السجلّ ولم يدخل — لا يُعدّ حاضراً
                ['name' => 'منى الزهراني', 'email' => 'm@office.sa', 'join_time' => null, 'duration_sec' => 0],
            ],
        ]);

        $this->assertSame(2, $meeting->attendedCount());
        $this->assertSame(4, $meeting->invitedCount()); // العميل + المحامي + اسمان في الحقل
        // متوسّط (3600 + 1800) ÷ 2 = 2700 من 3600 = ٧٥٪
        $this->assertSame(75, $meeting->presenceRate());
    }

    public function test_presence_rate_is_null_without_actual_duration(): void
    {
        $meeting = $this->meeting([
            'duration_sec' => null,
            'zoom_participants_log' => [
                ['name' => 'أ', 'email' => 'a@x.sa', 'join_time' => '2026-09-01 11:00:00', 'duration_sec' => 600],
            ],
        ]);

        $this->assertSame(1, $meeting->attendedCount());
        $this->assertNull($meeting->presenceRate()); // نسبةٌ بلا مقام ليست نسبة
    }

    // ————— ٣ · التقرير يعدّ ما تعرضه الشاشة —————

    public function test_reports_count_the_derived_status_not_the_stored_one(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        // مخزّن «قادم»، وفات موعده، ولم يدخله أحد ⇒ الشاشة تعرضه «لم ينعقد»
        $missed = $this->meeting([
            'status' => 'قادم',
            'starts_at' => now()->subDays(3),
            'when_label' => 'قبل ثلاثة أيام · 10:00',
            'dur' => '60 دقيقة',
        ]);
        $this->assertSame('لم ينعقد', $missed->liveState()[1]);

        $this->actingAs($admin)->get('/admin/meetreports')->assertInertia(
            fn ($p) => $p->where('analytics.statusCounts.لم ينعقد', 1)
                ->where('analytics.statusCounts.قادم', 0)
                ->etc()
        );
    }

    public function test_rates_are_null_not_zero_when_nothing_was_measured(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->meeting(['status' => 'قادم', 'starts_at' => now()->addDay()]);

        // لا اجتماع منتهياً ولا مهامّ: الصفر يدّعي تغطيةً قيست ووُجدت معدومة
        $this->actingAs($admin)->get('/admin/meetreports')->assertInertia(
            fn ($p) => $p->where('analytics.aiCoverageRate', null)
                ->where('analytics.recordingCoverageRate', null)
                ->where('analytics.decisionRate', null)
                ->where('analytics.avgActualMinutes', null)
                ->etc()
        );

        $this->actingAs($admin)->get('/admin/meetmgmt')->assertInertia(
            fn ($p) => $p->where('kpis.decisionRate', null)->where('kpis.avgMinutes', null)->etc()
        );
    }

    // ————— ٤ · غرفةٌ لا تُفتح على جلسةٍ فاتت —————

    public function test_room_refuses_a_meeting_that_never_took_place(): void
    {
        $meeting = $this->meeting([
            'status' => 'قادم',
            'starts_at' => now()->subDays(2),
            'when_label' => 'قبل يومين · 09:00',
        ]);

        // طلبٌ برمجيّ يأخذ الرمز، وفتحُ الرابط في المتصفّح يعود إلى صفحة الاجتماع بالسبب لا إلى صفحة خطأ
        // (`RoomDetails::refuse` — 2026-09-26)
        $this->actingAs($this->employee())
            ->getJson('/employee/meetingroom?ref='.$meeting->ref)
            ->assertStatus(422);

        $this->actingAs($this->employee())
            ->get('/employee/meetingroom?ref='.$meeting->ref)
            ->assertRedirect('/employee/meeting?id='.$meeting->ref)
            ->assertSessionHas('error');
    }

    // ————— ٥ · بنيويّ: لا Markdown خامّ على الشاشة، ولا صفرٌ مختلق —————

    public function test_no_screen_renders_meeting_text_raw(): void
    {
        $ui = file_get_contents(resource_path('js/lib/meeting-ui.tsx'));
        $client = file_get_contents(resource_path('js/pages/meetings.tsx'));

        // نصّ نموذجٍ توليديّ يأتي بنجوم Markdown — العرض الخامّ يُظهرها للقارئ.
        // العرض الخامّ في JSX سطرٌ محتواه التعبير وحده؛ والتمرير لـRichText سِمةٌ داخل وسم.
        $bare = fn (string $src, string $expr) => in_array(
            $expr,
            array_map('trim', explode('
', $src)),
            true
        );
        $this->assertFalse($bare($ui, '{m.zoomSummary}'), 'ملخّص Zoom يُعرض خامّاً');
        $this->assertFalse($bare($client, '{activeDoc.body}'), 'محضر العميل يُعرض خامّاً');
        $this->assertStringContainsString('<RichText text={m.zoomSummary} />', $ui);
        $this->assertStringContainsString('<RichText text={activeDoc.body} />', $client);

        // ولا يُفتح باب الحقن لاحقاً — الاستعمال الفعليّ سِمةٌ بقيمة، لا ذكرٌ في تعليق
        $rich = file_get_contents(resource_path('js/lib/consult-ui.tsx'));
        $this->assertStringNotContainsString('dangerouslySetInnerHTML={', $rich);
    }

    public function test_no_screen_prints_a_fabricated_zero_attendance(): void
    {
        foreach (['js/pages/admin/meetings.tsx', 'js/pages/admin/meetlog.tsx', 'js/pages/admin/meetmgmt.tsx'] as $file) {
            $this->assertStringNotContainsString(
                'm.attend || 0',
                file_get_contents(resource_path($file)),
                "{$file}: «حضور 0%» رقمٌ يدّعي قياساً لم يقع"
            );
        }
    }

    // ————— ٦ · المزامنة من Zoom لا تمسّ معتمداً —————

    public function test_zoom_sync_button_is_refused_after_final_approval(): void
    {
        $meeting = $this->meeting([
            'approve' => 'معتمد', 'sum_approved' => true,
            'meet_id' => '91234567890',
            'summary' => 'الملخص المعتمد.', 'minutes' => 'المحضر المعتمد.',
        ]);

        // المزامنة تكتب decisions، وtoCard يُرسلها للعميل متى كان الاجتماع معتمداً
        $this->actingAs($this->employee())
            ->post("/employee/meetings/{$meeting->id}/zoom-sync")
            ->assertStatus(422);

        $meeting->refresh();
        $this->assertSame('الملخص المعتمد.', $meeting->summary);
        $this->assertNull($meeting->zoom_summary_at);
    }

    public function test_zoom_sync_still_works_before_approval(): void
    {
        $meeting = $this->meeting(['meet_id' => '91234567891']);

        // لا 422: الباب مفتوح ما لم تعتمد الإدارة (والرسالة صادقة حين لا بيانات لدى Zoom)
        $this->actingAs($this->employee())
            ->post("/employee/meetings/{$meeting->id}/zoom-sync")
            ->assertRedirect();
    }

    public function test_an_automatic_pull_freezes_the_attested_fields_only(): void
    {
        config(['services.glm.key' => 'test-key']);
        Http::fake();

        $meeting = $this->meeting([
            'approve' => 'معتمد', 'sum_approved' => true,
            'meet_id' => '91234567892',
            'summary' => 'الملخص المعتمد الذي وصل العميل.',
            'minutes' => 'المحضر المعتمد الذي وصل العميل.',
            'decisions' => [],
        ]);

        // الويبهوك والأمر المجدول يسلكان هذا الطريق نفسه — لا الزرّ وحده
        MeetingSummary::pull($meeting, app(ZoomService::class), [
            'summary_overview' => 'ناقش الطرفان مستجدات العقد.',
            'next_steps' => ['صياغة ملحق تعديل العقد'],
        ]);

        $meeting->refresh();
        $this->assertSame('الملخص المعتمد الذي وصل العميل.', $meeting->summary);
        $this->assertSame('المحضر المعتمد الذي وصل العميل.', $meeting->minutes);
        $this->assertSame([], $meeting->decisions ?? []);
        // ولا نداء ذكاءٍ لاستخلاص قرارات على معتمد
        Http::assertNothingSent();

        // والوقائع تبقى تُحدَّث: zoom_summary ليس ممّا شهدت به الإدارة
        $this->assertNotNull($meeting->zoom_summary_at);
        $this->assertStringContainsString('مستجدات العقد', (string) $meeting->zoom_summary);
    }

    public function test_the_screen_hides_the_sync_button_after_approval(): void
    {
        $ui = file_get_contents(resource_path('js/lib/meeting-ui.tsx'));

        // بمفتاح الحالة من الخادم (`statusKey`) لا بالنصّ العربيّ — الحارس نفسه: لا مزامنة بعد الاعتماد
        $this->assertStringContainsString("{statusKey === 'ended' && !locked && (", $ui);
    }

    // ————— ٧ · القائمة تفرز بالموعد وتحترم نافذة الدخول —————

    public function test_the_card_carries_the_two_other_halves_of_the_live_state(): void
    {
        // بعد أسبوع: قادمٌ، ولا يُدعى للدخول قبل أوانه
        $far = $this->meeting([
            'status' => 'قادم', 'starts_at' => now()->addWeek()->setTime(11, 0),
            'when_label' => 'بعد أسبوع · 11:00', 'dur' => '60 دقيقة',
        ])->toFullCard();
        $this->assertTrue($far['up']);
        $this->assertFalse($far['canJoin'], 'نافذة الدخول تُفتح قبل الموعد بخمس دقائق لا قبل أسبوع');

        // بعد دقيقتين: داخل النافذة
        $near = $this->meeting([
            'status' => 'قادم', 'starts_at' => now()->addMinutes(2),
            'when_label' => 'اليوم', 'dur' => '60 دقيقة',
        ])->toFullCard();
        $this->assertTrue($near['up']);
        $this->assertTrue($near['canJoin']);

        // فات ولم يدخله أحد: ليس قادماً ولا يُدخَل
        $missed = $this->meeting([
            'status' => 'قادم', 'starts_at' => now()->subDays(2),
            'when_label' => 'قبل يومين · 09:00', 'dur' => '60 دقيقة',
        ])->toFullCard();
        $this->assertFalse($missed['up']);
        $this->assertFalse($missed['canJoin']);
        $this->assertSame('لم ينعقد', $missed['status']);
    }

    public function test_the_list_screen_reuses_the_server_rules_and_drops_the_dead_code(): void
    {
        $ui = file_get_contents(resource_path('js/lib/meeting-ui.tsx'));

        // قاعدةُ النافذة لا تُعاد كتابتها في JS — تُقرأ من البطاقة
        $this->assertStringContainsString('{m.canJoin ? (', $ui);
        // والفرز بالموعد لا بترتيب الإنشاء
        $this->assertStringContainsString('new Date(a.startsAt).getTime() - new Date(b.startsAt).getTime()', $ui);
        // شيفرةٌ ميّتة لا تعود: قوائم «قبل/أثناء/بعد» المختلقة عُلّقت 2026-08-26 وبقيت سنةً
        $this->assertStringNotContainsString('{false &&', $ui);
        // وزرّ «الملخص» كان خيارَ وهم: كلا فرعيه يفتح الصفحة نفسها
        $this->assertStringNotContainsString('لم يُحفظ ملخص بعد', $ui);
    }
}
