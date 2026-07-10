<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // صلاحيات spatie وأدوار القوالب أولاً (قبل إسناد أي صلاحية)
        $this->call(PermissionSeeder::class);

        // حسابات تجريبية — حساب واحد لكل دور (كلمة المرور: password)
        $users = [
            ['name' => 'عبدالله محمد العتيبي', 'email' => 'client@salasel.test',   'role' => Role::Client,   'avatar_initials' => 'ع م'],
            ['name' => 'منيرة الحربي',          'email' => 'employee@salasel.test', 'role' => Role::Employee, 'avatar_initials' => 'م ح'],
            ['name' => 'أ. سارة القحطاني',       'email' => 'lawyer@salasel.test',   'role' => Role::Lawyer,   'avatar_initials' => 'س ق', 'title' => 'أ.'],
            ['name' => 'الإدارة العليا',         'email' => 'admin@salasel.test',    'role' => Role::Admin,    'avatar_initials' => 'إ ع'],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'role' => $u['role'],
                    'avatar_initials' => $u['avatar_initials'],
                    'title' => $u['title'] ?? null,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }

        $this->call([
            BranchSeeder::class,
            StaffSeeder::class,   // يُثري employee@/lawyer@ ببيانات العمل والصلاحيات + يضيف أ. خالد
            TicketSeeder::class,
            CaseSeeder::class,
            ExecutionSeeder::class,
            NotificationSeeder::class,
            AppointmentSeeder::class,
            ConsultSeeder::class,
            MeetingSeeder::class,
            DocumentSeeder::class,
            InvoiceSeeder::class,
        ]);
    }
}
