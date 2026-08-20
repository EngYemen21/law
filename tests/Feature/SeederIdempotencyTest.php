<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * تقارب بذرة الحسابات على قاعدة قائمة (لا يلزم migrate:fresh):
 * كان التنازع «صفّ يحمل البريد وصفّ آخر يحمل (الهويّة+الدور)» يفجّر قيد التفرّد
 * users_national_id_role_unique — الآن المرساة الهويّة+الدور ويُؤرشف بريد الصفّ الدخيل.
 */
class SeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_converges_when_email_is_held_by_a_different_row(): void
    {
        // قاعدة قائمة: إدارة قديمة تحمل الهويّة 1000000001 ببريد قديم،
        // وصفّ آخر (عميل قديم) يحمل بريد الإدارة الجديد kfykfy2020@gmail.com — سيناريو الانفجار الأصلي
        $oldAdmin = User::create([
            'name' => 'إدارة قديمة', 'email' => 'old.admin@x.sa', 'role' => Role::Admin,
            'national_id' => '1000000001', 'phone' => '0500000001',
            'password' => Hash::make('x'), 'status' => 'active',
        ]);
        $emailHolder = User::create([
            'name' => 'مستخدم قديم', 'email' => 'kfykfy2020@gmail.com', 'role' => Role::Client,
            'national_id' => '9999999999', 'phone' => '0599999999',
            'password' => Hash::make('x'), 'status' => 'active',
        ]);

        $this->seed(DatabaseSeeder::class); // كانت ترمي UniqueConstraintViolationException

        // صفّ الهويّة هو الذي اعتُمد وحُدّث بالبريد الجديد — لا صفّ إدارة مكرر
        $admin = User::where('national_id', '1000000001')->where('role', Role::Admin)->firstOrFail();
        $this->assertTrue($admin->is($oldAdmin->fresh()));
        $this->assertSame('kfykfy2020@gmail.com', $admin->email);
        $this->assertSame('+966537434000', $admin->phone);

        // الصفّ الدخيل بقي (لا حذف صامت لبيانات حقيقية) لكن بريده أُرشف وتحرّر
        $this->assertStringStartsWith('archived+', $emailHolder->fresh()->email);

        // إعادة البذر مرة ثانية آمنة (متقاربة) — لا استثناء ولا تكرار
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(1, User::where('national_id', '1000000001')->where('role', Role::Admin)->count());
    }

    public function test_fresh_seed_creates_exactly_the_four_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, User::count());
        foreach ([
            ['1000000001', Role::Admin, 'kfykfy2020@gmail.com'],
            ['1000000002', Role::Lawyer, 'law@salasel.sa'],
            ['1000000003', Role::Employee, 'emp@salasel.sa'],
            ['1000000004', Role::Client, 'm.bander.it@gmail.com'],
        ] as [$nid, $role, $email]) {
            $u = User::where('national_id', $nid)->where('role', $role)->first();
            $this->assertNotNull($u, "الحساب {$nid}/{$role->value} مفقود");
            $this->assertSame($email, $u->email);
        }
    }
}
