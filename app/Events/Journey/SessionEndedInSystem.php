<?php

namespace App\Events\Journey;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * **أُنهيت جلسةٌ في النظام وغرفتُها على Zoom ما زالت مفتوحة** — حدثٌ بعد التزام الإنهاء.
 *
 * يطلقه كلُّ انتقالٍ يختم الجلسة في النظام من `events()`: الإنهاء (`Consult\EndSession` ·
 * `Meeting\EndMeeting`) من زرّ الطاقم أو شبكة النسيان، وحسمُ الغياب (`Consult\MarkNoShow` ·
 * `Meeting\MarkMeetingMissed`) — فغرفةٌ فُتحت على Zoom وضاع حدثُ بدئها لا تبقى مفتوحة لجلسةٍ
 * حُسمت. ويُغلق مستمعُه الغرفة في الخلفيّة (`EndZoomMeetingJob`). قرارُ «هل تُغلق؟» هنا في موضعٍ
 * واحد (`forEnd`) كي لا تتباعد الانتقالات في شرطه.
 *
 * **إغلاقٌ لا حذف:** «إنهاء» على غرفةٍ لم تبدأ لا يفعل شيئاً (يقبله `ZoomService::endMeeting`
 * نجاحاً)، والاجتماع يبقى على Zoom — لأنّ «لم ينعقد» يُعاد جدولته على الاجتماع نفسه
 * (`BookingMoved::apply` يحدّثه)، وZoom دليلُ انعقادٍ يُحيي «لم يحضر» (`ZoomSessionStarted`).
 * أمّا الحذف فلما لا عودة له: إلغاء الاجتماع، وإعادة جدولة الاستشارة (`DropZoomMeetingJob`).
 */
final class SessionEndedInSystem
{
    use Dispatchable;

    /**
     * مصدرُ إنهاءٍ جاء **من Zoom نفسه** (ويبهوك `meeting.ended`) — الغرفة أُغلقت هناك، فلا يُنادى
     * Zoom لإغلاقها ثانيةً. يمرّره الويبهوك في حمولة الانتقال (`source`)، ويُحفظ في سطر الرحلة.
     */
    public const VIA_ZOOM = 'zoom';

    /** زرّ الطاقم — الافتراض حين لا مصدر في الحمولة. يشترط جلسةً جارية (`isLive`). */
    public const VIA_STAFF = 'staff';

    /**
     * شبكة النسيان (`sessions:close-stale`) — جلسةٌ جارية بلغت مهلة النسيان. تُغلق غرفة Zoom
     * كزرّ الطاقم تماماً (قرار المالك 2026-09-26).
     */
    public const VIA_SAFETY_NET = 'safety_net';

    public function __construct(
        public readonly string $meetId,
        public readonly string $ref,
    ) {}

    /**
     * الحدث لانتقال إنهاء — أو لا شيء: جلسةٌ بلا غرفة Zoom (حضوريّة/هاتفيّة)، أو إنهاءٌ جاء من Zoom.
     *
     * @param  array<string, mixed>  $payload  حمولة الانتقال
     * @return list<self>
     */
    public static function forEnd(mixed $meetId, mixed $ref, array $payload): array
    {
        if (($payload['source'] ?? null) === self::VIA_ZOOM || blank($meetId)) {
            return [];
        }

        return [new self((string) $meetId, (string) $ref)];
    }
}
