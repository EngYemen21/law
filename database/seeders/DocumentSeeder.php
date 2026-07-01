<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Seeder;

class DocumentSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@salasel.test')->first();
        if (! $client) {
            return;
        }

        // نفس بيانات DATA.docsOut (صادرة) في الواجهة
        $docsOut = [
            ['name' => 'ملخص_الاستشارة.pdf', 'meta' => 'صادر · معتمد · 14 يونيو'],
            ['name' => 'مذكرة_قانونية.pdf', 'meta' => 'صادر · معتمد · 14 يونيو'],
            ['name' => 'بطاقة_الموعد.pdf', 'meta' => 'صادر · 12 يونيو'],
        ];

        // نفس بيانات DATA.docsUp (مرفوعة) في الواجهة
        $docsUp = [
            ['name' => 'عقد_التوريد.pdf', 'meta' => 'PDF · 1.2MB · تذكرة SB-2026-1042'],
            ['name' => 'الهوية_الوطنية.jpg', 'meta' => 'صورة · 480KB'],
            ['name' => 'مراسلات_البريد.pdf', 'meta' => 'PDF · 760KB'],
        ];

        foreach ($docsOut as $d) {
            Document::updateOrCreate(
                ['user_id' => $client->id, 'name' => $d['name'], 'direction' => 'out'],
                ['meta' => $d['meta']]
            );
        }

        foreach ($docsUp as $d) {
            Document::updateOrCreate(
                ['user_id' => $client->id, 'name' => $d['name'], 'direction' => 'up'],
                ['meta' => $d['meta']]
            );
        }
    }
}
