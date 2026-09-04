<?php

namespace Tests\Feature;

use App\Enums\AiSource;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\Consult;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\Ai\AiReviewPreview;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * **«فتح الملفّ» يقود قاصده إلى بابٍ يفتحه.**
 *
 * كانت البادئة `/lawyer` مثبَّتة في `AiReviewPreview::consultHref`، ومسارها محروسٌ
 * بـ`role:lawyer` — يفحص **الدور** لا الصلاحيّة، فلا يتجاوزه استثناء الإدارة. ومهمّة
 * `consult` من مهامّ الموظّف في الصندوق، فكان الموظّف والإداريّ يريان الرابط ثمّ
 * يُصدَّان عن وجهته.
 *
 * والحارس يفتح الوجهة **فعلاً** بعين قاصدها: مطابقةُ سلسلةٍ نصّيّة كانت ستمرّ ولو
 * صار المسار محروساً بصلاحيّةٍ لا يملكها.
 */
class AiReviewDestinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function consultRun(): array
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::all());

        $consult = Consult::create([
            'user_id' => $client->id, 'ref' => 'CN-DST-'.uniqid(), 'subject' => 'نزاع تجاري',
            'type' => 'استشارة', 'channel' => 'مرئية', 'status' => 'منتهية', 'session' => 'منتهية',
            'tone' => 'b-green', 'lawyer' => $lawyer->name, 'assigned_lawyer_id' => $lawyer->id,
            'ai_summary' => 'تحليلٌ مبدئيّ للوقائع المعروضة.',
        ]);

        $run = AiRun::create([
            'task_type' => 'consult', 'entity_type' => Consult::class, 'entity_id' => $consult->id,
            'entity_ref' => $consult->ref, 'status' => AiRun::STATUS_NEEDS_REVIEW,
            'source' => AiSource::AiSuccess->value,
        ]);

        return [$run, $consult, $lawyer];
    }

    public function test_open_the_file_lands_the_reviewer_where_they_are_allowed(): void
    {
        [$run, $consult, $lawyer] = $this->consultRun();

        $employee = User::factory()->create(['role' => Role::Employee]);
        $employee->syncPermissions(Permission::whereIn('name', Permissions::ROLE_PERMISSIONS['employee'])->get());
        $admin = User::factory()->create(['role' => Role::Admin]);

        foreach ([$lawyer, $employee, $admin] as $viewer) {
            $href = AiReviewPreview::for($run, $viewer)['href'] ?? null;

            $this->assertNotNull($href, "لا وجهة للدور {$viewer->role->value}");
            $this->assertStringContainsString($consult->ref, $href);

            // **الوجهة تُفتح فعلاً** — لا يكفي أن يبدو الرابط صحيحاً
            $this->actingAs($viewer)->get($href)->assertOk();
        }
    }

    /**
     * **لا يُبتّ في مخرجٍ لا يُرى.**
     *
     * كانت `case.classify` و`triage` تصلان الصندوق بلا معاينة (عشرة قيود في قاعدة
     * التطوير)، فيُضغط «اعتماد» على بياناتٍ وصفيّة عن مخرجٍ لم يُقرأ — ووسمُه «اعتماد
     * بشريّ» أوسعُ ممّا وقع. ومخرجُهما حقولٌ تُكتب في الملفّ وتوجّهه: نوع القضيّة
     * وقسمها، وقسم التذكرة وأولويّتها.
     */
    public function test_a_classification_run_shows_the_verdict_it_asks_to_approve(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);

        $case = LegalCase::create([
            'user_id' => $client->id, 'number' => 'CASE-PRV-'.uniqid(), 'type' => 'نزاع تجاري',
            'department' => 'القضايا التجارية', 'status' => 'نشطة', 'tone' => 'b-blue',
            'assigned_lawyer_id' => $lawyer->id,
        ]);

        $run = AiRun::create([
            'task_type' => 'case.classify', 'entity_type' => LegalCase::class,
            'entity_id' => $case->id, 'entity_ref' => $case->number,
            'status' => AiRun::STATUS_NEEDS_REVIEW, 'source' => AiSource::AiSuccess->value,
        ]);

        $preview = AiReviewPreview::for($run);

        $this->assertNotNull($preview, 'قيدٌ بلا معاينة يُعتمد على وصفٍ لا على مخرج');
        $this->assertStringContainsString('نزاع تجاري', $preview['text']);
        $this->assertStringContainsString('القضايا التجارية', $preview['text']);
    }

    /** وبلا مُشاهدٍ معلوم تبقى الوجهة على حالها — الاستدعاءات القديمة لا تنكسر. */
    public function test_the_default_destination_is_unchanged(): void
    {
        [$run, $consult] = $this->consultRun();

        $this->assertSame("/lawyer/consult?ref={$consult->ref}", AiReviewPreview::for($run)['href']);
    }
}
