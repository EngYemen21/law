<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Execution;
use App\Models\ExecutionDocument;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **ثلاث قدراتٍ للموظّف فُصلت عن صلاحيّاتٍ أوسع** (قرار المالك 2026-09-18).
 *
 * كان موظّفٌ بصلاحيّة القسم وحدها يسجّل الأحكام، وينزّل مرفقات المكتب كلّه، ويشغّل كلّ
 * التسجيلات. الحكمُ والتسجيلات مختبَرةٌ في `EmployeeCourtActionsTest` و`SessionRecordingAccessTest`،
 * ومرفقات المحادثات في `ConversationFileDownloadTest`. وهنا: مسار تنزيل مستند التنفيذ الثاني،
 * والهجرة التي منحت القدرتين لمن كان يملكهما كي لا ينقطع عمل أحد.
 */
class EmployeeSplitPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function employee(array $perms): User
    {
        $u = User::factory()->create(['role' => Role::Employee, 'status' => 'active']);
        $u->syncPermissions(Permission::whereIn('name', $perms)->get());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u;
    }

    /** مسار تنزيل مستند التنفيذ يتبع القاعدة نفسها — والرابط يُخفى عمّن يُردّ. */
    public function test_the_execution_document_route_needs_the_download_permission(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['role' => Role::Client]);
        $exec = Execution::create(['user_id' => $client->id, 'number' => 'EX-SP-1', 'subject' => 'تنفيذ حكم', 'stage' => 2]);
        Storage::disk('local')->put("exec-docs/{$exec->id}/a.pdf", '%PDF-1.4');
        $doc = ExecutionDocument::create(['execution_id' => $exec->id, 'label' => 'السند', 'status' => 'مرفوع', 'path' => "exec-docs/{$exec->id}/a.pdf"]);
        $url = route('exec-flow.documents.download', [$exec, $doc]);

        $viewer = $this->employee(['إدارة القضايا والأتعاب']);
        $this->actingAs($viewer)->get($url)->assertForbidden()->assertSee('تنزيل مرفقات الملفات');
        $this->actingAs($viewer);
        $this->assertFalse($doc->toData()['canDownload'], 'الاسم بلا رابط');

        $downloader = $this->employee(['إدارة القضايا والأتعاب', Permissions::DOWNLOAD_FILES]);
        $this->actingAs($downloader)->get($url)->assertOk();
        $this->actingAs($downloader);
        $this->assertTrue($doc->toData()['canDownload']);

        // العميل صاحب الملفّ لا تمسّه الصلاحيّة
        $this->actingAs($client)->get($url)->assertOk();
    }

    /**
     * **الهجرة لا تقطع عمل أحد:** من كان يبلغ المرفقات أو التسجيلات بصلاحيّة القسم (مباشرةً أو عبر
     * قالب الدور) يُمنح القدرة المستقلّة، ومن لم يكن لا يُمنح. و«تسجيل الأحكام» لا تُمنح لأحد.
     */
    public function test_the_migration_grants_what_each_employee_already_had(): void
    {
        $ticketDesk = $this->employee(['إدارة التذاكر']);
        $caseDesk = $this->employee(['إدارة القضايا والأتعاب', 'إجراءات المحكمة والجلسات']);
        $reception = $this->employee(['استقبال الاستشارات']);
        $meetings = $this->employee(['إرسال دعوات الاجتماعات']);
        // المصنع يمنح الموظّف والمحامي كلّ الصلاحيّات — تُفرَّغ ليبقى ما يختبره كلّ سطر وحده
        $viaPreset = $this->employee([]);
        $viaPreset->assignRole('خدمة عملاء'); // القالب يحمل «إدارة التذاكر» و«استقبال الاستشارات»
        $nothing = $this->employee(['إشعارات العملاء']);
        $lawyer = User::factory()->create(['role' => Role::Lawyer]);
        $lawyer->syncPermissions(Permission::whereIn('name', ['إدارة القضايا والأتعاب'])->get());

        // الحالة قبل الهجرة: الصلاحيّات قائمةٌ غيرُ ممنوحة
        (include database_path('migrations/2026_09_18_000002_split_employee_file_and_recording_permissions.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $has = fn (User $u, string $p) => $u->fresh()->hasPermissionTo($p);

        $this->assertTrue($has($ticketDesk, Permissions::DOWNLOAD_FILES));
        $this->assertFalse($has($ticketDesk, Permissions::PLAY_RECORDINGS));
        $this->assertTrue($has($caseDesk, Permissions::DOWNLOAD_FILES));
        $this->assertFalse($has($caseDesk, Permissions::RECORD_RULINGS), 'الأحكام لا تُمنح تلقائياً ولو ملك إجراءات المحكمة');
        $this->assertTrue($has($reception, Permissions::PLAY_RECORDINGS));
        $this->assertFalse($has($reception, Permissions::DOWNLOAD_FILES));
        $this->assertTrue($has($meetings, Permissions::PLAY_RECORDINGS));
        $this->assertTrue($has($viaPreset, Permissions::DOWNLOAD_FILES), 'ما وصل عبر القالب يُحتسب');
        $this->assertTrue($has($viaPreset, Permissions::PLAY_RECORDINGS));
        $this->assertFalse($has($nothing, Permissions::DOWNLOAD_FILES));
        $this->assertFalse($has($nothing, Permissions::PLAY_RECORDINGS));
        $this->assertFalse($lawyer->fresh()->getDirectPermissions()->contains('name', Permissions::DOWNLOAD_FILES), 'الموظّف وحده');

        // وتكرارها لا يضاعف شيئاً
        (include database_path('migrations/2026_09_18_000002_split_employee_file_and_recording_permissions.php'))->up();
        $this->assertSame(1, $ticketDesk->fresh()->getDirectPermissions()->where('name', Permissions::DOWNLOAD_FILES)->count());
    }

    /** الثلاث في الكتالوج وسقف الموظّف، وخارج قالب «خدمة عملاء» — تمنحها الإدارة. */
    public function test_the_three_permissions_are_granted_from_the_staff_tab(): void
    {
        foreach ([Permissions::RECORD_RULINGS, Permissions::DOWNLOAD_FILES, Permissions::PLAY_RECORDINGS] as $p) {
            $this->assertContains($p, Permissions::all(), $p);
            $this->assertContains($p, Permissions::ROLE_PERMISSIONS['employee'], $p);
            $this->assertNotContains($p, Permissions::PRESETS['خدمة عملاء'], $p);
        }
    }
}
