<?php

namespace Database\Seeders;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Seeder;

class MeetingSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@salasel.test')->first();
        if (! $client) {
            return;
        }

        // نفس بيانات DATA.meetings في الواجهة
        $meetings = [
            ['title' => 'استشارة مرئية — نزاع تجاري', 'when' => 'الاثنين 29 يونيو · 11:30 ص', 'up' => true, 'link' => true, 'minutes' => false, 'summary' => false],
            ['title' => 'استشارة مرئية — نزاع عقاري', 'when' => 'الجمعة 12 يونيو · 10:00 ص', 'up' => false, 'link' => false, 'minutes' => true, 'summary' => true],
        ];

        foreach ($meetings as $m) {
            Meeting::updateOrCreate(
                ['user_id' => $client->id, 'title' => $m['title']],
                [
                    'when_label' => $m['when'],
                    'is_up' => $m['up'],
                    'has_link' => $m['link'],
                    'has_minutes' => $m['minutes'],
                    'has_summary' => $m['summary'],
                ]
            );
        }
    }
}
