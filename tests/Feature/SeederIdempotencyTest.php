<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
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

    /**
     * الحسابات الأساسية الأربعة تُنشأ صحيحة وبلا تكرار.
     * DatabaseSeeder لم يعد يستدعي DemoDataSeeder (البيانات التجريبية صارت صريحة)،
     * فالثابت هو أن كل هويّة/دور أساسيّ له حساب واحد ببريده الصحيح ولا حسابات سواها.
     */
    public function test_fresh_seed_creates_the_four_core_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([
            ['1000000001', Role::Admin, 'kfykfy2020@gmail.com'],
            ['1000000002', Role::Lawyer, 'law@salasel.sa'],
            ['1000000003', Role::Employee, 'emp@salasel.sa'],
            ['1000000004', Role::Client, 'm.bander.it@gmail.com'],
        ] as [$nid, $role, $email]) {
            $matches = User::where('national_id', $nid)->where('role', $role)->get();
            $this->assertCount(1, $matches, "الحساب {$nid}/{$role->value} مفقود أو مكرّر");
            $this->assertSame($email, $matches->first()->email);
        }

        // ولا حساب سواها: البذّار آمن على الإنتاج ولا يحقن حسابات تجريبية
        $this->assertSame(4, User::count(), 'البذّار أنشأ حسابات خارج الأربعة الأساسية.');
    }

    /** إعادة البذر لا تُضاعف الحسابات الأساسية (تقارب حقيقي لا مجرّد عدم انفجار). */
    public function test_reseeding_does_not_duplicate_core_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = User::whereIn('national_id', ['1000000001', '1000000002', '1000000003', '1000000004'])->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, User::whereIn('national_id', ['1000000001', '1000000002', '1000000003', '1000000004'])->count());
    }

    /**
     * صلاحية خرجت من الكتالوج («إدارة الفروع» بعد إزالة كيان الفرع) كانت تبقى صفّاً حيّاً
     * ومُسنَدة لكل مستخدم لم يُعدَّل — البذّار يُنشئ ولا يحذف، وsyncPermissions يفصلها عن
     * أدوار القوالب فقط. الآن تُقلَّم من الصفوف ومن إسناد المستخدمين والأدوار معاً.
     */
    public function test_seeder_prunes_permissions_that_left_the_catalogue(): void
    {
        $this->seed(PermissionSeeder::class);

        $stale = Permission::findOrCreate('إدارة الفروع', 'web');
        $role = SpatieRole::findOrCreate('إداري', 'web');
        $role->givePermissionTo($stale);

        $staff = User::factory()->create(['role' => Role::Employee]);
        $staff->givePermissionTo($stale);
        $this->assertTrue($staff->fresh()->hasPermissionTo('إدارة الفروع'));

        $this->seed(PermissionSeeder::class);

        $this->assertNull(Permission::where('name', 'إدارة الفروع')->first());
        $this->assertNotContains('إدارة الفروع', $staff->fresh()->permissions->pluck('name')->all());
        $this->assertNotContains('إدارة الفروع', $role->fresh()->permissions->pluck('name')->all());

        // الكتالوج الحيّ سليم بعد التقليم — لا حذف عرَضيّ
        $this->assertSame(
            count(Permissions::all()),
            Permission::where('guard_name', 'web')->count()
        );
    }
}
