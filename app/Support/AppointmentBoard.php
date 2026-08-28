<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Appointment;
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

    /** @return array{appointments: array, counts: array, clients: array, lawyers: array} */
    public static function data(User $viewer): array
    {
        $appointments = Appointment::with(['user', 'consult', 'lawyerUser'])
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

        $todayDate = now()->format('Y-m-d');

        return [
            'appointments' => $appointments->values()->all(),
            'counts' => [
                'today' => $appointments->filter(fn ($a) => ($a['rawDate'] ?? null) === $todayDate)->count(),
                'upcoming' => $appointments->filter(fn ($a) => ($a['when'] ?? null) === 'up')->count(),
                'video' => $appointments->filter(fn ($a) => ($a['channel'] ?? null) === 'مرئية')->count(),
                'office' => $appointments->filter(fn ($a) => ($a['channel'] ?? null) === 'حضورية')->count(),
            ],
            'clients' => User::where('role', Role::Client)->orderBy('name')->get(['id', 'name', 'phone', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone ?? '', 'email' => $u->email ?? ''])
                ->all(),
            'lawyers' => User::where('role', Role::Lawyer)->orderBy('name')->get(['id', 'name', 'department'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'dept' => $u->department ?: 'القسم القانوني'])
                ->all(),
        ];
    }
}
