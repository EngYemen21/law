<?php

namespace App\Support;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Transitions\Consult\ProposeAppointment;
use App\Domain\Journey\Transitions\Consult\PublishAppointment;
use App\Domain\Journey\Workflow;
use App\Enums\Role;
use App\Models\Consult;
use App\Models\JourneyTransition;
use App\Models\User;
use App\Services\ZoomService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * **حجز موعد الجلسة بيد الطاقم** (قرار المالك 2026-09-14).
 *
 * العميل لا يختار موعداً. بعد السداد:
 *   - الموظّف يقترح (`propose`) ⇒ «بانتظار اعتماد الموعد»، ولا يصل العميلَ شيء.
 *   - الإدارة تعتمد اقتراحه أو تعدّله وتعتمده، أو تحجز بنفسها (`publish`) ⇒ يُنشر للعميل.
 *
 * هنا ما يسبق الانتقال ولا يجوز داخل معاملته: حسمُ المحامي والوقت من المُدخل، ونداءُ Zoom.
 */
final class ConsultAppointments
{
    private const TYPES = ['حضورية' => 'office', 'مرئية' => 'video', 'هاتفية' => 'phone'];

    /**
     * @param  array{date:string,time:string,lawyer_id?:int|null,type?:string|null,place?:string|null}  $input
     */
    public static function propose(Consult $consult, User $actor, array $input): Consult
    {
        $slot = self::slot($consult, $input, base: null);

        return Workflow::run(new ProposeAppointment, $consult, $actor, $slot);
    }

    /**
     * اعتماد اقتراحٍ قائم (مع تعديلٍ اختياريّ) أو حجزٌ مباشر من الإدارة.
     *
     * @param  array{date?:string|null,time?:string|null,lawyer_id?:int|null,type?:string|null,place?:string|null}  $input
     */
    public static function publish(Consult $consult, User $actor, array $input = []): Consult
    {
        WebTimeLimit::raise(90);

        $pending = $consult->status === ConsultStatus::AwaitingAppointmentApproval->value
            && $consult->appointment?->status === AppointmentStatus::PendingApproval->value
                ? $consult->appointment
                : null;

        $base = $pending === null ? null : [
            'date' => $pending->starts_at?->format('Y-m-d'),
            'time' => $pending->starts_at?->format('H:i'),
            'lawyer_id' => $pending->lawyer_id,
            'type' => self::TYPES[str_replace('استشارة ', '', (string) $pending->type)] ?? self::typeOf($consult),
            'place' => $pending->place,
        ];

        $slot = self::slot($consult, $input, $base);

        $slot['changes'] = $base === null ? [] : self::changes($base, $slot);
        $slot['proposer_id'] = $pending === null ? null : JourneyTransition::where('entity_type', 'Consult')
            ->where('entity_id', $consult->id)
            ->where('transition', 'consult.propose-appointment')
            ->latest('id')
            ->value('actor_id');

        $isVideo = $slot['type'] === 'video';
        // `duration` لـZoom اسميّ (`SessionWindow::nominalMinutes`) — الجلسة تنتهي بختمها لا ببلوغه
        $slot['zoom'] = $isVideo
            ? app(ZoomService::class)->createMeeting("استشارة {$consult->ref} — {$consult->subject}", SessionWindow::nominalMinutes(), false, $slot['starts_at'])
            : null;

        if ($isVideo && empty($slot['zoom']['id'])) {
            Log::error('Zoom: تعذّر إنشاء اجتماع الاستشارة — تُنشر بلا رابط جلسة', ['consult' => $consult->ref]);
        }

        try {
            return Workflow::run(new PublishAppointment, $consult, $actor, $slot);
        } catch (\Throwable $e) {
            if (! empty($slot['zoom']['id'])) {
                app(ZoomService::class)->deleteMeeting((string) $slot['zoom']['id']);
            }
            throw $e;
        }
    }

    /**
     * يحسم الموعد من المُدخل فوق الأساس (الاقتراح القائم إن وُجد).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $base
     * @return array<string, mixed>
     */
    private static function slot(Consult $consult, array $input, ?array $base): array
    {
        $date = $input['date'] ?? $base['date'] ?? null;
        $time = $input['time'] ?? $base['time'] ?? null;

        if (blank($date) || blank($time)) {
            throw ValidationException::withMessages(['time' => 'حدّد تاريخ الموعد ووقته.']);
        }

        $startsAt = Carbon::parse($date.' '.$time);
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['time' => 'لا يمكن اختيار موعد في الماضي، فضلاً اختر وقتاً لاحقاً.']);
        }

        $type = (string) ($input['type'] ?? $base['type'] ?? self::typeOf($consult));
        if (! in_array($type, self::TYPES, true)) {
            $type = self::typeOf($consult);
        }

        // مسافة الحجز على تقويم المحامي (تُحفظ في `duration_min` لمنع التعارض) — لا عمرُ الجلسة
        $duration = LawyerAvailability::slotMinutes();
        $lawyer = self::lawyer($consult, $input['lawyer_id'] ?? $base['lawyer_id'] ?? null, $startsAt, $duration);

        $meta = ConsultBooking::meta($type);
        $place = $type === 'office' && ! empty($input['place'] ?? $base['place'] ?? null)
            ? (string) ($input['place'] ?? $base['place'])
            : $meta['place'];

        return [
            'lawyer_id' => $lawyer->id,
            'lawyer' => $lawyer->name,
            'starts_at' => $startsAt,
            'duration' => $duration,
            'day' => $startsAt->format('Y-m-d'),
            'time' => $startsAt->format('H:i'),
            'type' => $type,
            'place' => $place,
        ];
    }

    /**
     * المحامي: المختار، ثمّ محامي الاستشارة، ثمّ محامي التذكرة، ثمّ أوّل متخصّصٍ متفرّغ.
     * لا «أقدم إداريّ» نائباً — الطاقم يحجز فيختار محامياً فعلياً أو وقتاً آخر.
     */
    private static function lawyer(Consult $consult, mixed $lawyerId, Carbon $startsAt, int $duration): User
    {
        $candidateId = $lawyerId ?: ($consult->assigned_lawyer_id ?: $consult->ticket?->assigned_lawyer_id);

        $lawyer = $candidateId
            ? User::where('role', Role::Lawyer)->where('status', 'active')->find((int) $candidateId)
            : null;

        $lawyer ??= LawyerAvailability::assignLawyer(
            (string) ($consult->specialty ?: $consult->ticket?->department ?: ''),
            $consult->subject ?: $consult->ticket?->type,
            $startsAt,
            $duration
        );

        if ($lawyer === null) {
            throw ValidationException::withMessages(['lawyer_id' => 'لا يتوفّر محامٍ في هذا الوقت — اختر وقتاً آخر أو محامياً محدّداً.']);
        }

        return $lawyer;
    }

    private static function typeOf(Consult $consult): string
    {
        return self::TYPES[(string) $consult->channel] ?? 'office';
    }

    /**
     * ما عدّلته الإدارة على الاقتراح — بالعربية ليُشعَر به الموظّف.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $slot
     * @return list<string>
     */
    private static function changes(array $base, array $slot): array
    {
        $changes = [];

        if ($base['date'] !== $slot['day'] || $base['time'] !== $slot['time']) {
            $changes[] = "الموعد: {$base['date']} {$base['time']} ← {$slot['day']} {$slot['time']}";
        }
        if ((int) $base['lawyer_id'] !== (int) $slot['lawyer_id']) {
            $changes[] = "المحامي: {$slot['lawyer']}";
        }
        if ($base['type'] !== $slot['type']) {
            $changes[] = 'نوع الجلسة: '.ConsultBooking::meta($slot['type'])['label'];
        }

        return $changes;
    }
}
