<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **تبويب «تسجيل الموظفين» — المرحلة ٢: حذف ساعات دوام الموظّف** (قرار المالك 2026-10-01).
 *
 * كانت تُحفظ نصّاً وتُعرض فقط، ولا يقرؤها الحجز ولا الحضور. دوام المكتب في «إعدادات النظام» باقٍ،
 * وهو وحده مصدر شرائح الحجز.
 */
class StaffWorkHoursRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_columns_are_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'work_start'));
        $this->assertFalse(Schema::hasColumn('users', 'work_end'));
    }

    public function test_the_staff_card_and_form_carry_no_hours(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->create(['role' => Role::Admin]);

        // حقلا الساعات إن أُرسلا يُتجاهلان — لا رفض ولا كتابة
        $this->actingAs($admin)->post(route('admin.staff.store'), [
            'name' => 'سلمى الغامدي', 'role' => 'employee', 'job_title' => 'موظف خدمة عملاء',
            'email' => 'hours@salasel.test', 'mobile' => '0551234567', 'nid' => '1012345678',
            'dept' => 'خدمة العملاء', 'payType' => 'salary', 'salary' => 8000, 'perms' => ['إدارة التذاكر'],
            'start' => '08:00', 'end' => '16:00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $card = User::where('email', 'hours@salasel.test')->sole()->staffCard();
        $this->assertArrayNotHasKey('start', $card);
        $this->assertArrayNotHasKey('end', $card);

        $page = (string) file_get_contents(resource_path('js/pages/admin/staff.tsx'));
        foreach (['أوقات الدوام', 'ساعات الدوام', 'الدوام اليومي', 'workHoursText'] as $gone) {
            $this->assertStringNotContainsString($gone, $page, $gone);
        }
    }
}
