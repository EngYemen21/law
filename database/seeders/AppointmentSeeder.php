<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\Seeder;

class AppointmentSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@salasel.test')->first();
        if (! $client) {
            return;
        }

        // نفس بيانات DATA.appts في الواجهة
        $appts = [
            ['id' => 'AP1', 'type' => 'مرئية', 'ico' => 'video', 'lawyer' => 'أ. سارة القحطاني', 'day' => 'الاثنين 29 يونيو 2026', 'time' => '11:30 ص', 'branch' => 'اجتماع إلكتروني', 'status' => 'مؤكد', 'tone' => 'b-green', 'when' => 'up'],
            ['id' => 'AP2', 'type' => 'حضورية', 'ico' => 'office', 'lawyer' => 'أ. خالد المالكي', 'day' => 'الأربعاء 01 يوليو 2026', 'time' => '01:00 م', 'branch' => 'الرياض — حي العليا', 'status' => 'مؤكد', 'tone' => 'b-green', 'when' => 'up'],
            ['id' => 'AP3', 'type' => 'حضورية', 'ico' => 'office', 'lawyer' => 'أ. ريم الزهراني', 'day' => 'الجمعة 12 يونيو 2026', 'time' => '10:00 ص', 'branch' => 'جدة — حي الروضة', 'status' => 'منتهٍ', 'tone' => 'b-grey', 'when' => 'past'],
        ];

        foreach ($appts as $a) {
            Appointment::updateOrCreate(
                ['user_id' => $client->id, 'ext_id' => $a['id']],
                [
                    'type' => $a['type'],
                    'ico' => $a['ico'],
                    'lawyer' => $a['lawyer'],
                    'day' => $a['day'],
                    'time' => $a['time'],
                    'branch' => $a['branch'],
                    'status' => $a['status'],
                    'tone' => $a['tone'],
                    'when_kind' => $a['when'],
                ]
            );
        }
    }
}
