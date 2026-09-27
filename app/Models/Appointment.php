<?php

namespace App\Models;

use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\GuardsJourneyState;
use App\Domain\Journey\Transitions\Consult\RescheduleConsult;
use App\Support\LawyerName;
use App\Support\SessionWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use GuardsJourneyState;

    protected $fillable = [
        'user_id', 'ticket_id', 'ext_id', 'type', 'ico', 'lawyer', 'lawyer_id', 'day', 'time',
        'starts_at', 'duration_min', 'place', 'status', 'tone', 'when_kind',
        // ذاكرةُ إعادة الجدولة: الموعد الملغى يبقى مرتبطاً باستشارته بسببه ووقت إلغائه
        'consult_id', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'cancelled_at' => 'datetime',
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

    /**
     * **اسم محامي الموعد كما يراه العميل** — نظير `Consult::lawyerForClient`.
     *
     * كانت القاعدة منسوخةً في بطاقة الموعد وحدها، فكتب مركز تنبيهات لوحة العميل وتقويمُه المشترَك
     * العمودَ النصّيّ `lawyer` خاماً (الاسم الكامل). دالّةٌ واحدة يقرؤها كلّ ما يصل العميل.
     */
    public function lawyerForClient(string $fallback = '—'): string
    {
        return LawyerName::forClient($this->lawyer_id ? $this->lawyerUser : null, $this->lawyer, $fallback);
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

    /**
     * **فات الموعد دون أن تبدأ جلسته؟** — مهلة الفوات من **البداية** (`SessionWindow`)، لا
     * «البداية + المدّة»: الجلسة لا مدّة لها تنتهي بها (قرار المالك 2026-09-26).
     * `when_kind` المخزّنة ثابتة ولا تتحدّث بمرور الوقت، فهي احتياطُ الصفّ بلا موعد.
     */
    public function isPast(): bool
    {
        if (! $this->starts_at) {
            return $this->when_kind === 'past';
        }

        return SessionWindow::isMissed($this->starts_at);
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

        // **الجارية قيد الجلسة حتى تُختم** — كان لها سقفٌ «المدة + 180د» تُعرض بعده «تم الحضور»
        // وهي ما زالت منعقدة. النهاية حدث الختم (`EndSession`) — فيصير الموعد «تم الحضور» من هناك
        // لا من الساعة؛ والمنسيّة تُختم بانتقال الإنهاء نفسه بعد مهلة النسيان (`sessions:close-stale`).
        if ($session === SessionState::Live->value) {
            return ['up', 'قيد الجلسة', 'b-blue'];
        }

        // **الجلسة المختومة ماضيةٌ مهما تكن الساعة** — وكان هذا الفحص **بعد** فحص الساعة،
        // فجلسةٌ مدّتها ساعة انتهت في دقيقتها العشرين تبقى «قادمة» أربعين دقيقة: يُعلن بنر
        // «لديك موعد استشارة مجدول اليوم» على العميل بعد أن ودّع محاميه. المقياس هو
        // انتهاء الجلسة لا انقضاء الخانة المحجوزة لها.
        if ($session === SessionState::Ended->value) {
            return ['past', 'تم الحضور', 'b-green'];
        }

        // والإلغاء المخزَّن كذلك: موعدٌ أُلغي قبل وقته ليس «قادماً» حتى تحلّ ساعته
        if (in_array($this->status, ['ملغي', 'ملغى', 'ملغاة'], true)) {
            return ['past', $this->status, 'b-grey'];
        }

        if (! $this->isPast()) {
            return ['up', $this->status, $this->tone];
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
            // العميل يرى «الاسم. الحرف»؛ والطاقم الاسمَ كاملاً
            'lawyer' => $viewer?->isClient() ? $this->lawyerForClient() : $this->lawyer,
            'day' => $this->dayLabel(),
            'time' => $this->timeLabel(),
            'place' => $this->place,
            'status' => $status,
            'tone' => $tone,
            'when' => $when,
            // بيانات بطاقة الموعد الغنيّة (حقيقيّة)
            'client' => $this->user?->name,
            'consultRef' => $this->consult?->ref,
            // جسر إجراءات لوحة المواعيد: إعادة الجدولة/«لم يحضر» تمرّان عبر الاستشارة المرافقة
            'consultId' => $this->consult?->id,
            'consultRescheduleCount' => (int) ($this->consult?->reschedule_count ?? 0),
            // أزرار الموعد في الجدول (إعادة الجدولة · لم يحضر) بحارسَي الخادم — لا بحالة الموعد وحدها
            'consultCanReschedule' => $this->consult !== null && (new RescheduleConsult)->guard($this->consult, []) === null,
            'consultCanMarkNoShow' => (bool) $this->consult?->canMarkNoShow(),
            'pay' => $this->consult?->paid_at ? 'مدفوع' : 'بانتظار السداد',
            // رابط الجلسة المرئية الحقيقي داخل المنصّة — فارغ لغير المرئية أو لفاقدي صلاحية الحضور (يُخفى الزرّ)
            // canJoin + فحص الصلاحية شرطان لازمان: بلا الحكمين كان الزرّ يظهر ويردّ الخادم 403
            'joinLink' => $this->consult?->channel === 'مرئية'
                && $this->consult->canJoin()
                && ($viewer === null || $viewer->isAdmin() || $viewer->isClient() || $viewer->can('إجراء الجلسات المرئية') || $viewer->can('استقبال الاستشارات'))
                ? $this->consult->joinLink($viewer)
                : '',
        ];
    }
}
