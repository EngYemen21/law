<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\CaseHearing;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * يحوّل صفوف TimelineQuery (النوع + المعرّف) إلى بطاقات عرض.
 *
 * **قاعدة هذا الملفّ: لا اشتقاق هنا.** كل حقل حالة أو صلاحية دخول يُستمدّ من البانِي
 * الموثوق في النموذج نفسه — `Appointment::liveState` · `Meeting::toCard` ·
 * `CaseHearing::toData` · `Consult::toClientCard`. سبب القاعدة عطل حقيقي أبلغ عنه مستخدم:
 * اجتماع عُرض «قادم» بينما حارس الدخول يردّ «انتهت الجلسة» — لأن هذا الملفّ كان يقرأ العمود
 * المخزَّن بينما الحارس يقرأ liveState(). أي منطق حالة يُعاد بناؤه هنا سيتباعد عن مصدره حتماً.
 *
 * وتُحمَّل نماذج **الصفحة الحالية وحدها** مجمّعةً بالنوع — استعلام لكل نوع ظاهر في الصفحة،
 * لا استعلام لكل صفّ (N+1) ولا تحميل لتاريخ الحساب كلّه.
 */
class TimelineCard
{
    /** @param  Collection<int, object>  $rows  صفوف الاتحاد (kind · model_id) */
    public static function hydrate(Collection $rows, User $viewer): array
    {
        $byKind = $rows->groupBy('kind')->map->pluck('model_id');

        $models = [
            'appointment' => $byKind->has('appointment')
                ? Appointment::with(['user', 'consult'])->findMany($byKind['appointment'])->keyBy('id') : collect(),
            'consult' => $byKind->has('consult')
                // invoice محمَّلة مسبقاً: toClientCard() تقرأ $this->invoice?->number،
                // فبلا تحميلها استعلامٌ إضافيّ لكل استشارة في الصفحة (N+1).
                ? Consult::with(['appointment', 'user', 'invoice'])->findMany($byKind['consult'])->keyBy('id') : collect(),
            'hearing' => $byKind->has('hearing')
                ? CaseHearing::with('legalCase')->findMany($byKind['hearing'])->keyBy('id') : collect(),
            'meeting' => $byKind->has('meeting')
                ? Meeting::findMany($byKind['meeting'])->keyBy('id') : collect(),
        ];

        return $rows->map(function (object $r) use ($models, $viewer): ?array {
            $m = $models[$r->kind][$r->model_id] ?? null;

            return $m === null ? null : match ($r->kind) {
                'appointment' => self::appointment($m, $viewer),
                'consult' => self::consult($m),
                'hearing' => self::hearing($m),
                'meeting' => self::meeting($m, $viewer),
                default => null,
            };
        })->filter()->values()->all();
    }

    /** المصدر: Appointment::toCard — يبني حالته من liveState داخلياً. */
    private static function appointment(Appointment $a, User $viewer): array
    {
        $c = $a->toCard($viewer);

        return [
            'kind' => 'موعد', 'kindKey' => 'appointment', 'tone' => 'b-cyan',
            'id' => $c['id'], 'title' => 'موعد: '.$c['type'],
            'day' => $c['day'], 'time' => $c['time'], 'where' => $c['place'],
            'status' => $c['status'], 'statusTone' => $c['tone'], 'when' => $c['when'],
            // joinLink من البانِي: فارغ لغير المرئية فيُخفى الزرّ بدل أن يُعرض ويفشل
            'joinLink' => $c['joinLink'],
            'cardUrl' => '/appointments/'.rawurlencode((string) $c['id']).'/card.pdf',
        ];
    }

    /** المصدر: Consult::toClientCard — فيه canJoin وmissed وsession الحيّة. */
    private static function consult(Consult $co): array
    {
        $c = $co->toClientCard();

        // isMissed(): فاتت بلا جلسة — كانت «بانتظار الجلسة» أبدية متناقضة مع «لم يحضر» في المواعيد
        $status = EventStatus::forConsult($co);

        return [
            'kind' => 'استشارة', 'kindKey' => 'consult', 'tone' => 'b-blue',
            'id' => $c['ref'], 'title' => 'استشارة: '.$c['subject'],
            'day' => $c['when'], 'time' => null, 'where' => $c['place'],
            'status' => $status, 'statusTone' => EventStatus::toneFor($status),
            'when' => ($co->starts_at && $co->starts_at->isFuture()) ? 'up' : 'past',
            // canJoin من البانِي: الرابط لا يُعرض قبل إطلاقه (‏5 دقائق قبل الموعد)
            'joinLink' => $c['canJoin'] ? $c['slink'] : '',
        ];
    }

    /** المصدر: CaseHearing::toData — فيه lapsed المحسوبة. */
    private static function hearing(CaseHearing $h): array
    {
        $d = $h->toData();

        return [
            'kind' => 'جلسة قضية', 'kindKey' => 'hearing', 'tone' => 'b-amber',
            'id' => $h->legalCase?->number ?? (string) $d['id'],
            'title' => $d['title'].' — قضية '.($h->legalCase?->number ?? ''),
            'day' => $d['day'], 'time' => $d['time'], 'where' => $d['court'],
            // المدّة المتوقّعة إن أُدخلت (قرار المالك 2026-09-26) — null ⇒ لا مدّة تُعرض
            'durationMin' => $d['durationMin'],
            // lapsed: «مجدولة» فات موعدها ⇒ «فائتة — بانتظار النتيجة» بدل حالة كاذبة
            'status' => EventStatus::forHearing($h),
            // لون الحالة من `CaseHearing::liveTone` — كانت كلّ جلسةٍ غير فائتة كهرمانيّة (المنعقدة والملغاة معاً)
            'statusTone' => $h->liveTone(),
            'when' => ($h->starts_at && $h->starts_at->isFuture()) ? 'up' : 'past',
            'joinLink' => '',
        ];
    }

    /** المصدر: Meeting::toCard — يبني status وtone من liveState وlink من canJoin. */
    private static function meeting(Meeting $mt, User $viewer): array
    {
        $c = $mt->toCard();

        return [
            'kind' => 'اجتماع', 'kindKey' => 'meeting', 'tone' => 'b-green',
            'id' => $c['ref'], 'title' => $c['title'],
            'day' => $c['when'], 'time' => null, 'where' => 'غرفة المنصة',
            'status' => $c['status'], 'statusTone' => $c['tone'],
            'when' => $c['up'] ? 'up' : 'past',
            // canJoin من البانِي: كان الرابط يُعرض دائماً فيُدعى المستخدم لجلسة تردّه بـ403
            'joinLink' => $c['canJoin'] ? $mt->joinLink($viewer) : '',
        ];
    }
}
