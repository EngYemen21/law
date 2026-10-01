<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\LegalDocument;
use App\Models\MeetRequest;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Services\LegalAiService;
use App\Support\AppointmentCardPdf;
use App\Support\DecisionTasks;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use App\Support\UploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **ثوابت صارت إعدادات — المرحلة ٣** (تدقيق الإعدادات 2026-09-30): كلّ اختبارٍ يغيّر الإعداد ويُثبت أنّ السلوك
 * أو النصّ تبعه — وكانت منقوشةً في أوامر المجدول وتوليد المهامّ والمستندات.
 */
class SettingsConstantsStageThreeTest extends TestCase
{
    use RefreshDatabase;

    private function set(string $key, mixed $value): void
    {
        Setting::put($key, $value);
        SettingsRegistry::flush();
    }

    public function test_a_hearing_lapses_after_the_configured_minutes(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-2026-7301', 'type' => 'نزاع تجاري', 'status' => 'منظورة', 'tone' => 'b-blue', 'update_text' => '—',
        ]);
        $hearing = $case->hearings()->create([
            'title' => 'الجلسة الأولى', 'day' => now()->toDateString(), 'court' => 'الدائرة التجارية', 'status' => 'مجدولة',
            'starts_at' => now()->subHours(3),
        ]);

        $this->artisan('hearings:auto-lapse')->assertSuccessful();
        $this->assertSame('مجدولة', $hearing->fresh()->status, 'الافتراض يوم كامل');

        $this->set('hearing_lapse_after_minutes', 120);
        $this->artisan('hearings:auto-lapse')->assertSuccessful();

        $this->assertNotSame('مجدولة', $hearing->fresh()->status);
    }

    /** كان شرح «إغلاق الاجتماع» يَعِد بانتهاء الدعوة معه (12 ساعة) والكود ينهيها بعد 6 منقوشة — صار إعداداً مستقلّاً. */
    public function test_an_unconfirmed_invite_expires_after_its_own_setting(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $at = now()->subHours(8);
        $invite = fn (string $ref) => MeetRequest::create([
            'user_id' => $client->id, 'ref' => $ref, 'service' => 'خدمة', 'type' => 'استشارة مرئية',
            'day' => $at->toDateString(), 'time' => $at->format('H:i'), 'sent_by' => 'المكتب', 'stage' => MeetRequest::STAGE_SENT,
        ]);

        $this->set('meet_invite_expire_minutes', 720);
        $kept = $invite('MR-7301');
        $this->artisan('zoom:auto-close-missed')->assertSuccessful();
        $this->assertSame(MeetRequest::STAGE_SENT, $kept->fresh()->stage, 'ثماني ساعات دون الـ12 المضبوطة');

        $this->set('meet_invite_expire_minutes', 360);
        $this->artisan('zoom:auto-close-missed')->assertSuccessful();
        $this->assertSame(MeetRequest::STAGE_EXPIRED, $kept->fresh()->stage);

        $this->assertStringNotContainsString('صلاحية دعوته', SettingsRegistry::field('meeting_autoclose_minutes')['hint']);
    }

    public function test_decision_tasks_take_their_due_date_and_text_from_one_setting(): void
    {
        $this->set('decision_task_due_days', 3);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $consult = Consult::create([
            'user_id' => User::factory()->create(['role' => Role::Client])->id, 'ref' => 'CN-7301', 'subject' => 'نزاع', 'channel' => 'مرئية',
            'status' => 'منتهية', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id, 'decisions' => ['إعداد مذكّرة الردّ'],
        ]);
        DecisionTasks::suggest($consult, app(LegalAiService::class));

        DecisionTasks::create($consult->fresh(), app(LegalAiService::class), $lawyer);

        $task = Task::where('assigned_to', $lawyer->id)->sole();
        $this->assertSame(now()->addDays(3)->toDateString(), $task->due_at->toDateString());
        $this->assertSame('خلال 3 أيام', $task->due);
    }

    public function test_the_document_header_takes_the_english_name_and_licence_from_settings(): void
    {
        $this->assertSame('', LegalDocument::defaultHeader()['licenseNo'], 'لا رقم ترخيصٍ منقوش');

        $this->set('office_name_en', 'Test Law Firm');
        $this->set('office_license_no', 'LIC-7301');

        $header = LegalDocument::defaultHeader();
        $this->assertSame('Test Law Firm', $header['officeNameEn']);
        $this->assertSame('LIC-7301', $header['licenseNo']);
    }

    public function test_the_arrival_advice_follows_the_setting_on_the_card_and_the_page(): void
    {
        $this->set('office_arrival_minutes', 30);

        $card = AppointmentCardPdf::html([
            'no' => 'AP-1', 'type' => 'حضوري', 'day' => 'الأحد', 'time' => '10:00', 'place' => 'المقرّ',
            'client' => 'عميل', 'lawyer' => 'محامٍ', 'consultRef' => 'CN-1', 'address' => 'الرياض', 'paid' => true, 'payLabel' => 'مدفوع',
        ]);

        $this->assertStringContainsString('يُرجى الحضور قبل الموعد بـ30 دقيقة', $card);
        $this->assertSame(30, $this->get(route('login'))->viewData('page')['props']['settings']['office_arrival_minutes']);
    }

    /** بقايا المرحلة ٢: «بربع ساعة» في رسالة رفض البدء كانت نصّاً لا يتبع الإعداد. */
    public function test_the_staff_start_refusal_names_the_configured_window(): void
    {
        $this->set('consult_staff_start_minutes', 30);

        $this->assertSame('30 دقيقة', SessionWindow::staffStartLabel());
        $this->assertSame(30, $this->get(route('login'))->viewData('page')['props']['settings']['consult_staff_start_minutes']);
    }

    /** حدّ الرفع ثابتٌ واحد (قرار المالك) — ونظيره في الواجهة يطابقه، فلا يَعِد النصّ بحدٍّ يرفضه الخادم. */
    public function test_the_frontend_upload_limits_mirror_the_server(): void
    {
        $ts = (string) file_get_contents(resource_path('js/lib/upload-limits.ts'));
        preg_match('/ATTACHMENT_MB = (\d+);/', $ts, $attachment);
        preg_match('/DOCUMENT_MB = (\d+);/', $ts, $document);

        $this->assertSame(UploadLimits::ATTACHMENT_KB, (int) $attachment[1] * 1024);
        $this->assertSame(UploadLimits::DOCUMENT_KB, (int) $document[1] * 1024);
    }
}
