<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **لا يلمس اختبارٌ قرصَ التطوير.** (قيسَ 2026-09-11)
 *
 * كان `AdminResetDatabaseTest` ينادي «تصفير البيانات» والقرصُ حقيقيّ، و`resetDatabase` يحذف
 * `ticket-docs` و`case-docs` و`recordings` و`exec-docs` كاملةً — فكلّ تشغيلٍ للحزمة يمحو مرفقات
 * المكتب على جهاز التطوير. ظهر حين صار تنزيلُ مرفقات قضيّةٍ تجريبيّة ٤٠٤ بعد دقائق من إنشائها.
 */
class TestsNeverTouchTheDevDiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_test_runs_on_a_fake_local_disk(): void
    {
        $root = str_replace('\\', '/', (string) Storage::disk('local')->path(''));

        $this->assertStringContainsString('framework/testing/disks/local', $root, 'القرص المحليّ في الاختبار مزيَّف لا حقيقيّ');
        $this->assertStringNotContainsString('storage/app/private', $root);
    }

    public function test_resetting_the_database_never_deletes_the_real_documents(): void
    {
        $real = storage_path('app/private/ticket-docs/__guard__/keep.txt');
        @mkdir(dirname($real), 0777, true);
        file_put_contents($real, 'يبقى');

        try {
            $admin = User::factory()->create(['role' => Role::Admin]);
            $this->actingAs($admin)->post(route('admin.reset-database'), ['confirm' => 'RESET']);

            $this->assertFileExists($real, 'التصفير في الاختبار يعمل على القرص المزيَّف وحده');
        } finally {
            @unlink($real);
            @rmdir(dirname($real));
        }
    }
}
