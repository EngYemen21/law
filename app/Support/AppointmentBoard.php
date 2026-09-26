<?php

namespace App\Support;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Consult;
use App\Models\User;

/**
 * بيانات لوحة المواعيد (القائمة + العدّادات + قوائم العملاء والمحامين للحجز).
 *
 * لماذا: بعد توحيد التبويبات الزمنية صارت شاشتان تعرضان نفس اللوحة — «جدولة المواعيد»
 * القديمة وتبويب التقويم الموحّد. المصدر هنا واحد فلا تتباعد الأعمدة ولا العدّادات بينهما.
 * نُقل حرفياً من Employee\ScheduleController::index بلا تغيير في التشكيل.
 */
class AppointmentBoard
{
    /** سقف القائمة — نظرة عمل لا أرشيف المكتب. */
    private const LIMIT = 150;

    /**
     * **نافذةٌ حول اليوم لا «الأبعد مستقبلاً».**
     *
     * كان الترتيب `starts_at DESC` ثمّ `take(150)` — أي أنّ المحمَّل هو أبعد المواعيد في
     * المستقبل. والشاشة ترشّحه في المتصفّح بأربعة مرشّحات **ثلاثةٌ منها تسأل عن الماضي**
     * («تم الحضور»، «لم يحضر»، «مواعيد سابقة»)، فمتى تجاوز المكتب مئةً وخمسين موعداً لم
     * يبقَ في الحمولة ماضٍ إطلاقاً: تُظهر المرشّحات الثلاثة لا شيء، والبحث باسم موكّلٍ حضر
     * الشهر الماضي يردّ «لا نتائج» والصفُّ موجود.
     *
     * والنافذة تطابق نظيرتها في `Employee\CalendarController` (‏−٧/+٩٠) موسَّعةً للخلف،
     * لأنّ هذه الشاشة تُسأل عن الحضور والغياب لا عن القادم وحده.
     */
    private const PAST_DAYS = 60;

    private const FUTURE_DAYS = 120;

    /** @return array{appointments: array, counts: array, window: array, clients: array, lawyers: array, awaitingConsults: array} */
    public static function data(User $viewer): array
    {
        $from = now()->subDays(self::PAST_DAYS)->startOfDay();
        $to = now()->addDays(self::FUTURE_DAYS)->endOfDay();

        $windowed = fn () => Appointment::query()
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereBetween('starts_at', [$from, $to]));

        $appointments = $windowed()
            ->with(['user', 'consult', 'lawyerUser'])
            ->orderByRaw('starts_at IS NULL')
            ->orderBy('starts_at', 'desc')
            ->latest('id')
            ->take(self::LIMIT)
            ->get()
            ->map(function (Appointment $a) use ($viewer) {
                $card = $a->toCard($viewer);
                $card['rawStartsAt'] = $a->starts_at?->toIso8601String();
                $card['rawDate'] = $a->starts_at?->format('Y-m-d');
                $card['rawTime'] = $a->starts_at?->format('H:i');
                $card['lawyerId'] = $a->lawyer_id;
                $card['clientId'] = $a->user_id;
                $card['phone'] = $a->user?->phone ?? $a->consult?->phone ?? '';
                $card['channel'] = $a->consult?->channel ?? ($a->ico === 'video' ? 'مرئية' : ($a->ico === 'phone' ? 'هاتفية' : 'حضورية'));
                $card['subject'] = $a->consult?->subject ?? $a->type ?? '';

                return $card;
            });

        // **العدّادات من القاعدة لا من الشريحة.** كانت محسوبةً على الـ١٥٠ المحمَّلة، فتقول
        // «٥ اليوم» والمكتب فيه أكثر — نفس العلّة التي أُصلحت في عدّاد تنفيذ لوحة الموظّف.
        $totalInWindow = $windowed()->count();

        return [
            'appointments' => $appointments->values()->all(),
            'counts' => [
                'today' => Appointment::whereDate('starts_at', now()->toDateString())->count(),
                'upcoming' => Appointment::where('starts_at', '>=', now())->count(),
                'video' => $windowed()->whereHas('consult', fn ($q) => $q->where('channel', 'مرئية'))->count(),
                'office' => $windowed()->whereHas('consult', fn ($q) => $q->where('channel', 'حضورية'))->count(),
                // من القاعدة لا من الشريحة المقصوصة بـ١٥٠ — كانت شارة «بانتظار الاعتماد» تعدّ المحمَّل وحده
                'pendingApproval' => Appointment::where('status', AppointmentStatus::PendingApproval->value)->count(),
            ],
            // مدى النافذة والسقف — تعرضهما الشاشة فلا يُقرأ «لا نتائج» على أنّه «لا بيانات»
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'shown' => $appointments->count(),
                'total' => $totalInWindow,
                'capped' => $totalInWindow > self::LIMIT,
            ],
            'clients' => User::where('role', Role::Client)->orderBy('name')->get(['id', 'name', 'phone', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone ?? '', 'email' => $u->email ?? ''])
                ->all(),
            'lawyers' => User::where('role', Role::Lawyer)->orderBy('name')->get(['id', 'name', 'department'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'dept' => $u->department ?: 'القسم القانوني'])
                ->all(),
            'awaitingConsults' => self::awaitingConsults(),
        ];
    }

    /**
     * الاستشارات المدفوعة بانتظار موعدها — ما يختار منه نموذج «جدولة موعد».
     *
     * لماذا: كان النموذج يرسل العميل وحده فيحجز الخادم لأقدم استشاراته صامتاً، ولا يرى الموظّف
     * أنّ الاستشارة غير المدفوعة لا موعد لها. غير المدفوعة لا تُدرج هنا أصلاً.
     *
     * @return array<int, array{id:int, ref:string, clientId:int, subject:string, type:string, specialty:?string, lawyerId:?int, ticketNo:mixed}>
     */
    private static function awaitingConsults(): array
    {
        return Consult::with('ticket:id,number')
            ->where('status', ConsultStatus::AwaitingSchedule->value)
            ->orderBy('id')
            ->get(['id', 'ref', 'user_id', 'ticket_id', 'subject', 'channel', 'specialty', 'assigned_lawyer_id'])
            ->map(fn (Consult $c) => [
                'id' => $c->id,
                'ref' => (string) $c->ref,
                'clientId' => (int) $c->user_id,
                'subject' => (string) ($c->subject ?? ''),
                'type' => ConsultBooking::typeOfChannel($c->channel),
                'specialty' => $c->specialty,
                'lawyerId' => $c->assigned_lawyer_id,
                'ticketNo' => $c->ticket?->number,
            ])
            ->values()
            ->all();
    }
}
