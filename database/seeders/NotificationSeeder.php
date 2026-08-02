<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@salasel.test')->first();
        if (! $client) {
            return;
        }

        // نفس بيانات DATA.notifs في الواجهة
        $notifs = [
            ['ic' => 'ticket', 'tone' => 't-blue', 'text' => 'تم تحديث حالة التذكرة <b>SB-2026-1042</b> إلى «قيد التحليل».', 'time' => 'قبل ساعتين', 'unread' => true],
            ['ic' => 'cal', 'tone' => 't-green', 'text' => 'تم تأكيد موعدك يوم <b>الاثنين 29 يونيو</b> الساعة 11:30 ص.', 'time' => 'أمس', 'unread' => true],
            ['ic' => 'video', 'tone' => 't-cyan', 'text' => 'تم اعتماد ملخص اجتماعك ويمكنك الاطلاع عليه في قسم الاجتماعات.', 'time' => 'قبل يومين', 'unread' => false],
            ['ic' => 'card', 'tone' => 't-amber', 'text' => 'فاتورة <b>INV-2026-301</b> مستحقة السداد قبل 30 يونيو.', 'time' => 'قبل 3 أيام', 'unread' => true],
        ];

        // تجنّب التكرار عند إعادة التشغيل
        if (UserNotification::where('user_id', $client->id)->count() > 0) {
            return;
        }

        foreach ($notifs as $n) {
            UserNotification::create([
                'user_id' => $client->id,
                'icon' => $n['ic'],
                'tone' => $n['tone'],
                'body' => $n['text'],
                'time_label' => $n['time'],
                'is_read' => ! $n['unread'],
            ]);
        }
    }
}
