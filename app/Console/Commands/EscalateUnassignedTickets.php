<?php

namespace App\Console\Commands;

use App\Jobs\EscalateUnassignedTicketJob;
use App\Models\Ticket;
use App\Support\SettingsRegistry;
use Illuminate\Console\Command;

/**
 * كنس دوريّ للتذاكر التي بقيت بلا محامٍ — شبكة أمان خلف EscalateUnassignedTicketJob.
 *
 * الوظيفة تُرسَل لحظة فتح التذكرة، لكن ثلاث حالات تُفلت منها:
 *   • تذاكر فُتحت **قبل** هذه الميزة فبقيت بلا إسناد بلا أن يعلم أحد.
 *   • فشل الوظيفة نهائياً (تعذّر بريد/طابور متوقّف) فلا أحد يعرف أن التذكرة معلّقة.
 *   • إسناد أُلغي لاحقاً يدوياً فعادت التذكرة بلا محامٍ.
 *
 * الكنس يُرسل نفس الوظيفة، وهي بذاتها تتحقّق من الحالة قبل التصعيد — فتكرار التشغيل آمن.
 */
class EscalateUnassignedTickets extends Command
{
    // المعامل بلا قيمة افتراضيّة في التوقيع: افتراضه من الإعدادات كي تضبطه الإدارة بلا نشر
    // كود، ويبقى تمريره صريحاً ممكناً (الجدولة والاختبارات).
    protected $signature = 'tickets:escalate-unassigned {--minutes= : عمر التذكرة بالدقائق قبل التصعيد (الافتراض من الإعدادات)}';

    protected $description = 'تصعيد التذاكر المفتوحة التي بقيت بلا محامٍ مسنَد إلى الإدارة العليا';

    public function handle(): int
    {
        // مهلة قبل التصعيد: تمنع سباقاً مع الوظيفة المُرسَلة لحظة الفتح (وقد تكون في الطابور بعد)
        $option = $this->option('minutes');
        $minutes = $option === null || $option === '' ? SettingsRegistry::int('ticket_escalate_minutes') : (int) $option;
        $cutoff = now()->subMinutes($minutes);

        $tickets = Ticket::whereNull('assigned_lawyer_id')
            ->open()
            ->where('created_at', '<=', $cutoff)
            ->pluck('id');

        foreach ($tickets as $id) {
            EscalateUnassignedTicketJob::dispatch((int) $id);
        }

        $this->info("أُرسلت {$tickets->count()} تذكرة للتصعيد.");

        return self::SUCCESS;
    }
}
