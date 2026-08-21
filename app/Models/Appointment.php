<?php

namespace App\Models;

use App\Services\IcalendarService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    protected $fillable = [
        'user_id', 'ticket_id', 'ext_id', 'type', 'ico', 'lawyer', 'lawyer_id', 'day', 'time',
        'starts_at', 'duration_min', 'place', 'status', 'tone', 'when_kind',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
    ];

    /** ربط الراوت برقم العمل (ext_id) لا المعرّف الداخلي — يطابق نمط LegalCase/Invoice. */
    public function getRouteKeyName(): string
    {
        return 'ext_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    // المحامي المسند بالمعرّف (مصدر الحقيقة لحساب التعارض والتفرّغ)
    public function lawyerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lawyer_id');
    }

    // الاستشارة المرتبطة بالموعد (مصدر حالة السداد الحقيقية لبطاقة الموعد)
    public function consult(): HasOne
    {
        return $this->hasOne(Consult::class, 'appointment_id');
    }

    /** يوم الموعد بصياغة عربية مقروءة (الاثنين ٢٩ يونيو ٢٠٢٦) من starts_at الحقيقي؛ يرجع للنص المخزَّن إن غاب. */
    public function dayLabel(): string
    {
        return $this->starts_at?->locale('ar')->translatedFormat('l d F Y') ?: (string) $this->day;
    }

    /** وقت الموعد بصياغة عربية مقروءة (١١:٣٠ ص) من starts_at الحقيقي؛ يرجع للنص المخزَّن إن غاب. */
    public function timeLabel(): string
    {
        return $this->starts_at?->locale('ar')->translatedFormat('h:i A') ?: (string) $this->time;
    }

    /** هل انقضى وقت الموعد (البداية + المدة)؟ — when_kind المخزّنة ثابتة ولا تتحدّث بمرور الوقت */
    public function isPast(): bool
    {
        if (! $this->starts_at) {
            return $this->when_kind === 'past';
        }

        return $this->starts_at->copy()->addMinutes($this->duration_min ?: 60)->isPast();
    }

    /**
     * الحالة الحيّة المشتقّة [when, status, tone] — مصدر الحقيقة للحضور هو جلسة الاستشارة المرتبطة:
     * «جلسة جارية» ⇒ قيد الجلسة (تبقى في القادمة)، «منتهية» بعد الموعد ⇒ تم الحضور،
     * وموعد انقضى بلا جلسة ⇒ لم يحضر. الحالات الملغاة المخزّنة تُحترم كما هي.
     *
     * @return array{0:string,1:string,2:string}
     */
    public function liveState(): array
    {
        $session = $this->consult?->session;

        // «جلسة جارية» ضمن سقف زمني (المدة + 180د) — جلسة بُدئت ولم تُختم لا تُثبّت الموعد في «القادمة» أبدياً
        if ($session === 'جلسة جارية') {
            $withinCap = $this->starts_at === null
                || $this->starts_at->copy()->addMinutes(($this->duration_min ?: 60) + 180)->isFuture();

            // تجاوزت السقف بلا ختم: الجلسة بُدئت فعلاً ⇒ حضورٌ وقع (لا «لم يحضر»)
            return $withinCap
                ? ['up', 'قيد الجلسة', 'b-blue']
                : ['past', 'تم الحضور', 'b-green'];
        }

        if (! $this->isPast()) {
            return ['up', $this->status, $this->tone];
        }

        if (in_array($this->status, ['ملغي', 'ملغى', 'ملغاة'], true)) {
            return ['past', $this->status, 'b-grey'];
        }

        if ($session === 'منتهية') {
            return ['past', 'تم الحضور', 'b-green'];
        }

        return ['past', 'لم يحضر', 'b-red'];
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.appts)
    /** @param  User|null  $viewer  المستخدم الذي ستُعرض له البطاقة — يحدّد غرفة الجلسة الصحيحة لدوره. */
    public function toCard(?User $viewer = null): array
    {
        [$when, $status, $tone] = $this->liveState();

        return [
            'id' => $this->ext_id,
            'type' => $this->type,
            'ico' => $this->ico,
            'lawyer' => $this->lawyer,
            'day' => $this->dayLabel(),
            'time' => $this->timeLabel(),
            'place' => $this->place,
            'status' => $status,
            'tone' => $tone,
            'when' => $when,
            // بيانات بطاقة الموعد الغنيّة (حقيقيّة)
            'client' => $this->user?->name,
            'consultRef' => $this->consult?->ref,
            'pay' => $this->consult?->paid_at ? 'مدفوع' : 'بانتظار السداد',
            // إضافة للتقويم بتوقيت حقيقي (كان الرابط بلا dates فيفتح حدثاً فارغاً)
            'gcal' => IcalendarService::googleUrl(
                title: $this->type,
                details: 'موعد لدى مكتب المحاماة — المحامي: '.$this->lawyer,
                startsAt: $this->starts_at,
                durationMinutes: $this->duration_min ?: 60,
                locationUrl: $this->consult?->joinLink($viewer) ?: (string) $this->place,
            ),
            // رابط الجلسة المرئية الحقيقي داخل المنصّة — فارغ لغير المرئية (يُخفى الزرّ)
            'joinLink' => $this->consult?->channel === 'مرئية' ? $this->consult->joinLink($viewer) : '',
        ];
    }
}
