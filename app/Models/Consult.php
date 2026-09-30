<?php

namespace App\Models;

use App\Domain\Journey\Enums\AppointmentStatus;
use App\Domain\Journey\Enums\ConsultStatus;
use App\Domain\Journey\Enums\SessionState;
use App\Domain\Journey\GuardsJourneyState;
use App\Domain\Journey\Transitions\Consult\ApproveConsultAnalysis;
use App\Domain\Journey\Transitions\Consult\MarkNoShow;
use App\Domain\Journey\Transitions\Consult\RescheduleConsult;
use App\Enums\Role;
use App\Models\Concerns\LinksLegalDepartment;
use App\Models\Concerns\TracksRevisions;
use App\Support\ArabicCount;
use App\Support\Finance\InvoiceFactory;
use App\Support\LawyerName;
use App\Support\RecordingArchive;
use App\Support\SessionWindow;
use App\Support\SettingsRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * سجلّ الاستشارة (CN-2026-####) — يُنشأ عند تأكيد حجز الاستشارة من التذكرة،
 * ويحمل جلستها (بانتظار الجلسة → جلسة جارية → منتهية) وملخصها.
 */
class Consult extends Model
{
    use GuardsJourneyState;
    use LinksLegalDepartment;
    use TracksRevisions;

    // حالات دورة الحجز قبل الجلسة (تسعير → سداد → اختيار موعد) — تُستثنى من شاشة استقبال الجلسات
    /**
     * **كتالوج حالات الاستشارة — كلّ قيمةٍ هنا يكتبها مسارٌ حيّ.**
     *
     * وُجدت في الواجهة حالةٌ ثالثة `'محولة إلى قضية'` تُغذّي عدّاداً وتبويباً في ثلاث
     * شاشات، **ولا يكتبها أيّ مسارٍ في `app/`** (صفر ملفّات). وزرّ «تحويل إلى قضية»
     * يُحوّل **التذكرة** ولا يمسّ `consults.status` — فالحالة يتيمةٌ بالتصميم لا
     * بالسهو، وعدّادُها صفرٌ أبداً فيبدو أن المكتب لا يُحوّل استشارةً قطّ.
     *
     * وهذه سابقةٌ ثالثة في المشروع: `'بانتظار التأكيد'` عُلّق في
     * `Staff\MeetingController` للعلّة نفسها، و«جلسات اليوم» كان صفراً أبداً لحقلٍ
     * لا يُرسَل. ولذلك صار الكتالوج **مصدراً واحداً** يحرسه اختبار.
     *
     * ملاحظة: `'مغلقة'` حالةُ **قضيّة** لا استشارة — ذكرُها في تبويبات الاستشارات
     * كان خلطاً بين كيانين.
     */
    public const STATUSES = [
        // ما قبل الجلسة (دورة الحجز — `ConsultBooking`)
        'بانتظار التسعير',
        'بانتظار السداد',
        'بانتظار تحديد الموعد',
        // حجزُ الموظّف ينتظر اعتماد الإدارة قبل أن يصل العميل (قرار المالك 2026-09-14)
        'بانتظار اعتماد الموعد',
        // **`'مؤكد'` مطويّة — تُكتب على `Appointment` لا على `Consult`.**
        // `ConsultBooking` يكتبها على الموعد المرافق، والاستشارة تُضبط «جديدة». فكانت
        // حالةً ميتةً رابعة يقارنها عمودٌ في الكانبان ويحملها صفٌّ مبذور وحده. ونظيرتها
        // `'بانتظار التأكيد'` مطويّةٌ في `Staff\MeetingController` للعلّة نفسها.
        // 'مؤكد',
        // رحلة المعالجة (`Staff\ConsultController`)
        'جديدة',
        // **`'قيد مراجعة الموظف'` مطويّة (قرار المالك 2026-09-08).** كاتبُها الوحيد كان `take()`
        // خلف زرّ «استلام الاستشارة»، وقد أُزيل الزرّ ومعه المسار. فبقاؤها حالةٌ في
        // الكتالوج بلا كاتب — يحرسها `ConsultStatusCatalogueTest`. والعمل يبدأ من «جديدة».
        // 'قيد مراجعة الموظف',
        'بانتظار استكمال البيانات',
        'بانتظار اعتماد الموظف',
        'جاهزة للمحامي',
        'محالة للمحامي',
        'قيد الاستشارة',
        // النهايات
        'منتهية',
        'لم يحضر',
        'ملغاة',
    ];

    public const PRE_SESSION_STATUSES = ['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد', 'بانتظار اعتماد الموعد'];

    /**
     * **النهايات — للعرض والتبويب.** يطابق `CONSULT_TERMINAL_STATUSES` في الواجهة:
     * ثلاثتها خرجت من طابور العمل، فتُجمع في تبويبٍ واحد.
     */
    public const TERMINAL_STATUSES = ['منتهية', 'لم يحضر', 'ملغاة'];

    /**
     * **المغلقة — لا فعلَ يبعثها.** وهي غير النهايات الثلاث عمداً.
     *
     * `'لم يحضر'` **حالةُ تعافٍ لا نهاية**: العميل دفع ولم يحضر، فمسارُ إنقاذه
     * إعادةُ الجدولة — وهي الدورة التي كُتبت خصّيصاً لأن الفائتة «كانت تعلق بانتظار
     * الجلسة للأبد» (`ConsultNoShowRescheduleTest`). فمنعُها منه يُعيد ذلك العطل.
     *
     * أمّا «ملغاة» و«منتهية» فلا يُبعثان: طلبٌ أُلغي وأُشعر صاحبُه بإلغائه، وجلسةٌ
     * انعقدت وانتهت. وكان كلّ حارسٍ يمنع دورة الحجز وحدها — فتُحال استشارةٌ ملغاة
     * إلى محامٍ ويصل صاحبَها «سنوافيك بموعد الجلسة».
     */
    public const CLOSED_STATUSES = ['منتهية', 'ملغاة'];

    /**
     * **حالاتُ الجلسة — الكتالوج الذي لم يكن.**
     *
     * `session` عمودٌ نصّيّ حرّ يُكتب في أربعة مواضع متفرّقة بلا تعريفٍ يجمعها، فلم يكن
     * لأيّ حارسٍ سبيلٌ إلى فحصه. وثمرةُ غيابه أنّ `AutoCloseMissedConsults` يكتب
     * **«لم تُعقد»** ولا تعرفها شاشةٌ واحدة: الفرع الجامع في `consult-ui` يعرضها
     * **«منتهية» خضراء**، وشاشة استقبال الإدارة تعرضها «بانتظار الجلسة».
     *
     * فجلسةٌ فاتت تُعرض جلسةً تمّت بنجاح.
     *
     * @var list<string>
     */
    public const SESSIONS = ['بانتظار الجلسة', 'جلسة جارية', 'منتهية', 'لم تُعقد'];

    /**
     * نهاياتُ الجلسة — لا فعلَ بعدها في غرفة الاجتماع.
     *
     * @var list<string>
     */
    public const SESSION_ENDED = ['منتهية', 'لم تُعقد'];

    /**
     * **قاموس الأولويّة — واحدٌ للخادم والواجهة.**
     *
     * كانت الشاشة الواحدة تحمل قاموسين متعارضين: مرشِّحٌ يعرض **«عادية»** — وهي
     * أولويّةُ فرز **التذكرة** يكتبها الذكاء، ولا يقبلها هذا الخادم — ويُغفل
     * **«منخفضة»** التي تكتبها أزرارُ الدرج في الشاشة نفسها. فما تضبطه بيدك لا
     * تستطيع تصفيتَه، وما تصفّيه لا يقع.
     *
     * @var list<string>
     */
    public const PRIORITIES = ['عالية', 'متوسطة', 'منخفضة'];

    /**
     * **قنوات الاستشارة — الكتالوج الواحد.** يقرؤه التحقّق من المدخلات (`Staff\ConsultController`)
     * وانتقال التسعير (`PriceConsult`) الذي يسمح بتصحيح القناة، والواجهةُ نظيرُه في
     * `lib/consult-ui.tsx`. كانت القائمة مكرّرةً نصّاً في كلّ موضع، فتتفرّق مفرداتها عند أوّل تعديل.
     *
     * @var list<string>
     */
    public const CHANNELS = ['حضورية', 'مرئية', 'هاتفية'];

    protected $fillable = [
        'user_id', 'ticket_id', 'appointment_id', 'ref', 'subject', 'details', 'type', 'priority', 'channel',
        'lawyer', 'assigned_lawyer_id', 'specialty', 'employee', 'day', 'time', 'when_label', 'received_label', 'phone',
        // `duration_min`: المسافة المحجوزة على تقويم المحامي عند الحجز (يقرؤها `LawyerAvailability`
        // لمنع التعارض) — **لا عمرُ الجلسة**: الجلسة تنتهي بختمها (قرار المالك 2026-09-26).
        'starts_at', 'duration_min',
        'meet_id', 'meet_link', 'host_link', 'meet_password',
        'link_released_at', 'reminder_24h_sent_at', 'reminder_30m_sent_at', 'join_time', 'leave_time', 'duration_sec', 'transcript', 'recording_url', 'transcript_path', 'zoom_summary_at',
        'zoom_uuid', 'zoom_share_url', 'zoom_audio_url', 'zoom_participants_log', 'zoom_ai_next_steps',
        'status', 'session', 'session_notes', 'summary', 'summary_ai_original', 'summary_approved_at', 'summary_approved_by',
        'summary_lawyer_approved_at', 'summary_lawyer_approved_by',
        'summary_edited_at', 'summary_edited_by', 'summary_ai_source', 'session_finalized_at', 'zoom_summary', 'duration_label',
        'decisions', 'tasks_created', 'suggested_tasks',
        'price', 'vat', 'total', 'mins', 'priced_at', 'paid_at',
        'ai_done', 'ai_source', 'ai_class', 'ai_summary', 'ai_lawyer', 'missing', 'audit',
        'legal_department_id',
    ];

    protected $casts = [
        'ai_done' => 'boolean',
        'tasks_created' => 'boolean',
        'missing' => 'array',
        'audit' => 'array',
        'decisions' => 'array',
        'suggested_tasks' => 'array',
        'zoom_participants_log' => 'array',
        'zoom_ai_next_steps' => 'array',
        'starts_at' => 'datetime',
        'priced_at' => 'datetime',
        'summary_approved_at' => 'datetime',
        'summary_lawyer_approved_at' => 'datetime',
        'summary_edited_at' => 'datetime',
        'session_finalized_at' => 'datetime',
        'paid_at' => 'datetime',
        'link_released_at' => 'datetime',
        'reminder_24h_sent_at' => 'datetime',
        'reminder_30m_sent_at' => 'datetime',
        'join_time' => 'datetime',
        'leave_time' => 'datetime',
        'zoom_summary_at' => 'datetime',
        // ذاكرةُ إعادة الجدولة — خارج `$fillable` عمداً: لا يكتبها إلّا الانتقال وطلب العميل
        'reschedule_count' => 'integer',
        'reschedule_requested_at' => 'datetime',
    ];

    /**
     * هل يُفعَّل زر «الدخول إلى الجلسة»؟ للمرئية فقط، بعد إطلاق الرابط (قبل الموعد بـ5د).
     *
     * **الجلسة تنتهي حين تُنهى لا حين تبلغ الساعةُ مدّتها** (قرار المالك 2026-09-26). كان للدخول
     * سقفان محسوبان من المدّة — «الموعد + المدة + 30د» قبل البدء و«+ 180د» أثناءه — فتُغلق غرفةٌ
     * ما زال فيها الموكّل مع محاميه لأنّ الساعة قالت ذلك. الآن:
     * - **جارية** ⇒ مفتوحة حتى تُختم (`EndSession` من زرّ الطاقم أو ويبهوك Zoom؛ والمنسيّة تُختم
     *   بعد مهلة النسيان مع تنبيه الطاقم — `sessions:close-stale`).
     * - **لم تبدأ** ⇒ من إطلاق الرابط حتى تفوت (`SessionWindow::isMissed` — من البداية لا من مدّة).
     * - **مختومة** (انعقدت أو «لم تُعقد») ⇒ مغلقة.
     */
    public function canJoin(): bool
    {
        return $this->joinBlocker() === null;
    }

    /**
     * **لماذا لا يُدخَل إلى الغرفة الآن — `null` = يُدخَل.** مصدرُ `canJoin()` ونصُّ الرفض معاً
     * (غرفة العميل وغرفة الطاقم ونقطة توقيع Zoom)، فلا يُعرض سببٌ غير الذي منع.
     */
    public function joinBlocker(): ?string
    {
        $session = SessionState::tryFrom((string) $this->session);

        return match (true) {
            $this->channel !== 'مرئية' => SessionWindow::REFUSE_NOT_VIDEO,
            $session === SessionState::Ended => SessionWindow::REFUSE_ENDED,
            $session === SessionState::NotHeld => SessionWindow::REFUSE_MISSED,
            $session === SessionState::Live => null,
            ConsultStatus::tryFrom((string) $this->status) === ConsultStatus::Cancelled => SessionWindow::REFUSE_CANCELLED,
            // دورة الحجز لم تكتمل: لا رابطَ يُطلق ولا دخول قبل اعتماد الموعد (الجلسة الجارية فوق لا تُقطع)
            ConsultStatus::tryFrom((string) $this->status)?->isPreSession() === true => SessionWindow::REFUSE_BOOKING,
            $this->isMissed() => SessionWindow::REFUSE_MISSED,
            $this->link_released_at === null => SessionWindow::refuseNotOpen(),
            default => null,
        };
    }

    /**
     * **جاريةٌ الآن؟** — القاعدة الواحدة لـ«يجوز إنهاؤها» (`EndSession` لغير Zoom) ولعلَم `live`
     * في عقد الغرفة (`RoomDetails`) — نظيرُها `Meeting::isLive()`.
     */
    public function isLive(): bool
    {
        return $this->session === SessionState::Live->value;
    }

    /**
     * **فاتت دون أن تبدأ** — «بانتظار الجلسة» بعد مهلة الفوات من موعدها (`SessionWindow`).
     *
     * كانت تُقاس بـ«البداية + المدّة»، فصار طولُ الشريحة يقرّر متى يغيب الموكّل. الفوات كشفُ
     * غيابٍ من **البداية**، وجلسةٌ بدأت لا تفوت مهما طالت.
     */
    public function isMissed(): bool
    {
        return $this->session === SessionState::Waiting->value
            && SessionWindow::isMissed($this->starts_at);
    }

    /** سُجّلت الجلسة «لم تُعقد» (لم يحضر العميل أو حُسمت آليّاً). */
    public function isNotHeld(): bool
    {
        return $this->session === SessionState::NotHeld->value;
    }

    /** لون شارة الحالة (`ConsultStatus::tone`). */
    public function statusTone(): string
    {
        return ConsultStatus::tryFrom((string) $this->status)?->tone() ?? 'b-grey';
    }

    /** لون شارة الجلسة (`SessionState::tone`). */
    public function sessionTone(): string
    {
        return SessionState::tryFrom((string) $this->session)?->tone() ?? 'b-grey';
    }

    /**
     * لا يطلب العميل تغيير موعدٍ يبدأ خلال هذه الدقائق — يتّصل بالمكتب (قرار المالك 2026-09-25).
     * **الافتراض المُعلَن لا القيمة النافذة**: الإدارة تضبطها (`consult_reschedule_notice_minutes`) —
     * بالدقائق منذ 2026-09-26. يوم واحد.
     */
    public const RESCHEDULE_REQUEST_NOTICE_MINUTES = 1440;

    /**
     * **لماذا لا يستطيع العميل طلب تغيير موعده الآن — `null` = يستطيع.**
     *
     * مصدرٌ واحد للزرّ في «استشاراتي» ولردّ الخادم، فلا يُعرض زرٌّ يرفضه الخادم.
     *
     * كان الطلب للفائتة وحدها: من لا يستطيع الحضور غداً لم يكن له طريقٌ إلّا أن يفوته
     * الموعد. وكان بلا حالة: يُرسَل مرّاتٍ بلا حدّ وكلُّ مرّةٍ تُنبّه الإدارة كلّها، ولا
     * يرى الطاقم أنّ طلباً معلّق. فصار: القادمُ قبل ٢٤ ساعة والفائتُ، مرّةً حتى يُقضى.
     */
    public function rescheduleRequestBlocker(): ?string
    {
        $status = ConsultStatus::tryFrom((string) $this->status);
        $missed = $status === ConsultStatus::NoShow || $this->session === SessionState::NotHeld->value || $this->isMissed();
        $notice = SettingsRegistry::int('consult_reschedule_notice_minutes');
        $noticeEdge = now()->addMinutes($notice);

        return match (true) {
            $this->reschedule_requested_at !== null => 'طلبك السابق قيد المعالجة — سيتواصل معك المكتب.',
            // بلغت سقف إعادة الجدولة (`consult_reschedule_limit`) — ما بعده للإدارة العليا (قرار المالك 2026-09-29)
            (int) $this->reschedule_count >= RescheduleConsult::limit() => 'بلغت الاستشارة الحدّ الأقصى لتغيير الموعد — تواصل مع المكتب مباشرةً.',
            $status?->isClosed() === true, $this->session === SessionState::Ended->value => 'انتهت الاستشارة — لا موعد يُغيَّر.',
            $this->session === SessionState::Live->value => 'الجلسة منعقدة الآن.',
            $status?->isPreSession() === true => 'لم يُحدَّد موعد جلستك بعد — يصلك إشعارٌ به فور تحديده.',
            $missed => null,
            $this->starts_at === null => 'لم يُحدَّد موعد جلستك بعد — يصلك إشعارٌ به فور تحديده.',
            // والجملة تُبنى من القيمة نفسها بوحدتها الطبيعيّة — «أقلّ من يوم واحد» لا «أقلّ من 1440 دقيقة»
            $this->starts_at->lt($noticeEdge) => 'موعدك خلال أقلّ من '.ArabicCount::duration($notice).' — لتغييره تواصل مع المكتب مباشرةً.',
            default => null,
        };
    }

    /** تخصّص الاستشارة في `specialty` — يُربط بالكتالوج عند الحفظ. */
    protected function legalDepartmentSource(): string
    {
        return 'specialty';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * **كلّ مواعيد الاستشارة، الملغاة منها أيضاً** — الأحدث أوّلاً.
     *
     * `appointment()` الموعد الحاليّ وحده، ويُستبدل عند كلّ حجز؛ فكانت الإعادة تمحو أثر ما
     * قبلها. هذه السلسلة من `appointments.consult_id` الثابت.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class)->latest('id');
    }

    /**
     * مكان/قناة الجلسة المعروض — مصدر واحد بعد إزالة كيان «الفرع».
     * المرئية والهاتفية قناتان لا مكان لهما، والحضورية تأخذ مكان موعدها المقترن
     * (ConsultBooking يكتبه على الموعد)، وإلا عنوان المكتب من الإعدادات.
     */
    public function placeLabel(): string
    {
        return match ($this->channel) {
            'مرئية' => 'اجتماع إلكتروني',
            'هاتفية' => 'مكالمة هاتفية',
            default => $this->appointment?->place ?: SettingsRegistry::str('office_address'),
        };
    }

    /**
     * المكان للعرض في البطاقات — فارغ ما لم يُحجز موعد بعد.
     *
     * والموعد الملغى (بإعادة الجدولة) لا مكان له: يبقى مرتبطاً للسجلّ، لكنّ عرض مكانه
     * يوحي بموعدٍ قائم لاستشارةٍ تنتظر موعداً جديداً.
     */
    public function placeForCard(): string
    {
        if (! $this->appointment_id || $this->appointment?->status === AppointmentStatus::Cancelled->value) {
            return '';
        }

        return $this->placeLabel();
    }

    /** موعدها اقترحه موظّف ولم تعتمده الإدارة بعد — شأنٌ داخليّ لم يُنشر للعميل. */
    public function appointmentAwaitingApproval(): bool
    {
        $appointment = $this->appointment;

        return $this->appointment_id !== null
            && $appointment instanceof Appointment
            && $appointment->status === AppointmentStatus::PendingApproval->value;
    }

    /**
     * **اسم المستشار كما يراه العميل: «محمد. ب»** (قرار المالك 2026-09-11).
     *
     * بطاقة العميل تقنّعه، وقوالب البريد كانت تطبع `$consult->lawyer` الخام فيصله الاسم كاملاً.
     * دالّةٌ واحدة هنا يقرؤها القالب — لا تتكرّر قاعدة التقنيع في كلّ قالب، والنائبُ النصّيّ
     * («الإدارة العليا»، «المستشار المختص») يمرّ كما هو (انظر `LawyerName::forClient`).
     */
    public function lawyerForClient(string $fallback = '—'): string
    {
        return LawyerName::forClient($this->assigned_lawyer_id ? $this->assignedLawyer : null, $this->lawyer, $fallback);
    }

    /** مكان بطاقة العميل: لا يُكشف مكان موعدٍ مقترح قبل اعتماده (الطاقم يراه في `toCard`). */
    public function placeForClient(): string
    {
        return $this->appointmentAwaitingApproval() ? '' : $this->placeForCard();
    }

    // فاتورة الاستشارة (تُصدر عند تسعير الإدارة) — للعرض وحالة السداد
    /**
     * **الفاتورة الحاليّة — أحدثُها لا أوّلُها.**
     *
     * كانت `hasOne` بلا ترتيب، وهو آمنٌ ما دامت للاستشارة فاتورةٌ واحدة. ثمّ فُتح مسار
     * **تصحيح التسعير** فصار لها فاتورتان: ملغاةٌ وقائمة — وبدأت العلاقة تُرجع
     * **الملغاة** (أصغر معرّف).
     *
     * وأثرُ ذلك ماليٌّ مباشر: `markPaid` يُعلّم الفاتورةَ **الملغاة** مدفوعةً وتبقى
     * المستحقّة مستحقّةً؛ و`PaymentReconciler` يُسوّي دفعةَ البوّابة على الملغاة؛
     * و`invoiceNo` في البطاقة والتقرير يعرض رقماً أُلغي. كشفتها دورةُ المال الكاملة
     * لا اختبارُ بابٍ منفرد — لأنّ كلّ بابٍ وحده كان سليماً.
     *
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->latestOfMany();
    }

    // المحامي المسند بالمعرّف (لقياس سجلّ النجاح وربط الاستشارة بمحامٍ حقيقي)
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    /**
     * رابط انضمام الجلسة المرئية المضمّنة داخل المنصّة حصراً (لا روابط خارجية).
     * لكل دور غرفته: غرفة العميل /consults/room محروسة بـrole:client، فإعادتها
     * لموظف أو محامٍ أو إدارة تعني زرّاً يطرد صاحبه (403/إعادة توجيه).
     */
    public function joinLink(?User $user = null): string
    {
        $ref = (string) $this->ref;

        if ($user) {
            return match ($user->role) {
                Role::Client => url('/consults/room?ref='.$ref),
                Role::Lawyer => url('/lawyer/videoroom?ref='.$ref),
                Role::Employee => url('/employee/videoroom?ref='.$ref),
                Role::Admin => url('/admin/videoroom?ref='.$ref),
                default => url('/consults/room?ref='.$ref),
            };
        }

        return url('/consults/room?ref='.$ref);
    }

    /** رابط التبويب في لوحة التحكم بحسب الدور */
    public function portalUrlFor(?User $user = null): string
    {
        if ($user && $user->role === Role::Lawyer) {
            return url('/lawyer/consultrecv');
        }

        return url('/myconsults');
    }

    /**
     * موعد الاستشارة بصياغة عربية موحّدة (الاثنين ١٠ أغسطس ٢٠٢٦ · ١١:٣٠ ص) من starts_at الحقيقي.
     * يوحّد العرض عبر كل مسارات الإنشاء؛ ويرجع للنص المخزَّن when_label إن غاب starts_at.
     */
    public function whenLabel(): string
    {
        return $this->starts_at?->locale('ar')->translatedFormat('l d F Y · h:i A') ?: (string) $this->when_label;
    }

    /**
     * بطاقة العميل — حقول العرض الآمنة فقط لصفحة «استشاراتي».
     * تستثني عمداً: host_link (رابط المضيف/ZAK)، تحليل الذكاء الاصطناعي، سجل التدقيق،
     * الموظف المسند، والمستندات الناقصة — فهذه بيانات داخلية لا تخصّ العميل.
     */
    /**
     * **سبب منع اعتماد ملخّص الجلسة، أو `null` إن جاز** — شروط `approveSummary` الثلاثة في
     * موضعٍ واحد، يقرؤها المسار وعلمُ الزرّ (`canApproveSummary`). كان الزرّ يظهر لاستشارةٍ
     * ملغاةٍ لها ملخّص فيرفضه الخادم لأنّ الجلسة لم تنعقد.
     */
    public function summaryApprovalBlocker(): ?string
    {
        return match (true) {
            $this->summaryApproved() => 'اعتُمد هذا الملخّص ووصل العميل.',
            blank($this->summary) => 'لا ملخّص ليُعتمد — دوّن تدوين الجلسة أو اكتب التقرير أوّلاً.',
            // **لا «ملخّص جلسة» لجلسةٍ لم تنعقد** — كان يُعتمد لاستشارةٍ «جديدة» ويصل العميل (ع٢٢)
            $this->session !== SessionState::Ended->value => 'لم تنعقد هذه الجلسة — لا يُعتمد لها ملخّص جلسة.',
            default => null,
        };
    }

    /** «لم يحضر» الآن؟ — مصدر `MarkNoShow` وحارسه، كما يفحصهما المسار. */
    public function canMarkNoShow(): bool
    {
        $transition = new MarkNoShow;

        return in_array($this->session, $transition->from(), true) && $transition->guard($this, []) === null;
    }

    /** هل اعتمد إنسانٌ مفوَّض ملخّص هذه الاستشارة؟ */
    public function summaryApproved(): bool
    {
        return $this->summary_approved_at !== null;
    }

    /**
     * **نسبة الضريبة التي طُبّقت على هذه الاستشارة** — مستنتجةً من السعر والضريبة المجمَّدين
     * (`InvoiceFactory::taxFromFrozen`) لا من الإعداد الحاليّ. كانت الفاتورة والتقرير يكتبان
     * «(15%)» نصّاً، فلو غيّرت الإدارة النسبة لعرضا نسبةً تخالف المبلغ المطبوع بجوارها.
     *
     * و`null` قبل التسعير: لا نسبة طُبّقت بعد — ولا يُسأل الإعداد عنها، فبطاقات القوائم لا تستعلم
     * مرّةً لكلّ استشارةٍ غير مسعَّرة (`Setting::vatRate` استعلامٌ في كلّ نداء).
     */
    public function vatRate(): ?int
    {
        if ((int) $this->price <= 0) {
            return null;
        }

        return InvoiceFactory::taxFromFrozen((int) $this->price, (int) $this->vat)['vat_rate'];
    }

    public function toClientCard(): array
    {
        return [
            'id' => $this->id,
            'ref' => $this->ref,
            'subject' => $this->subject,
            'details' => $this->details,
            'specialty' => $this->specialty ?? '',
            'channel' => $this->channel,
            // «الاسم. الحرف» لمحامٍ مسنَد؛ والملفّ المرفوع للإدارة يبقى بنائبه (LawyerName)
            'lawyer' => $this->lawyerForClient(),
            'when' => $this->whenLabel(),
            // مكان الموعد المقترح لا يصل العميل قبل اعتماد الإدارة (`toCard` للطاقم يعرضه)
            'place' => $this->placeForClient(),
            'slink' => $this->channel === 'مرئية' ? $this->joinLink() : '',
            'canJoin' => $this->canJoin(), // زر الدخول معطّل حتى إطلاق الرابط قبل الموعد بـ5د
            // **علمان بمعنى واحدٍ في البطاقتين** (قرار المالك 2026-09-27): `missed` فات موعدها والجلسة
            // ما زالت منتظرة، و`notHeld` سُجّلت «لم تُعقد». كانت بطاقة العميل تجمعهما في `missed`
            // وبطاقة الطاقم لا — وشاشة الإدارة تُعيد بناء تعريف العميل يدويّاً
            'missed' => $this->isMissed(),
            'notHeld' => $this->isNotHeld(),
            'tone' => $this->statusTone(),
            'sessionTone' => $this->sessionTone(),
            // في دورة الحجز (تسعير · سداد · موعد) — علمٌ لا مرحلة: `bookingStage` يكشف «اعتماد الموعد» الداخليّ
            'inBooking' => in_array($this->status, self::PRE_SESSION_STATUSES, true),
            // طلب تغيير الموعد: هل يُتاح، وهل طلبٌ سابقٌ معلّق، ولماذا يُحجب — من `rescheduleRequestBlocker` وحده
            'rescheduleRequest' => [
                'pending' => $this->reschedule_requested_at !== null,
                'canRequest' => $this->rescheduleRequestBlocker() === null,
            ],
            'session' => $this->session,
            // اعتمادُ الإدارة للموعد شأنٌ داخليّ — يقرأ العميل «بانتظار تحديد الموعد»
            'status' => ConsultStatus::tryFrom((string) $this->status)?->clientLabel() ?? $this->status,
            /*
             * **ما ينتظره المكتبُ من الموكّل يصل الموكّل.**
             *
             * `requestDocs` يكتب «بانتظار استكمال البيانات» ويضع المطلوب في `missing`،
             * وسببُ الوقوف **فعلٌ على الموكّل**. وكانت البطاقة تُغفل `missing` فتسقط
             * الحالةُ في الفرع الجامع وتُعرض «بانتظار الجلسة» وتُحسب «قادمة مؤكدة» —
             * فيقرأ: «لا شيء عليك». والإشعارُ قناتُه الوحيدة، فإن مرّ عَلِق الملفّ بلا
             * خطأٍ في أيّ سجلّ.
             */
            'missing' => $this->missing ?? [],
            // **لا رأي قانونيّ يصل العميل قبل أن يعتمده محامٍ.** كان الملخّص يُكتب
            // بالنموذج ويُعرض فوراً تحت شارة «معتمد رسمياً» بلا مرور إنسان به.
            // والحجب هنا لا في الواجهة: حجبٌ واجهيّ يبقى النصّ فيه في حمولة
            // المتصفّح، فيُقرأ بأدوات المطوّر ويصل من لا يجوز أن يصله.
            'summary' => $this->summaryApproved() ? $this->summary : null,
            'summaryPending' => $this->summary !== null && ! $this->summaryApproved(),
            'summaryApproved' => $this->summaryApproved(),
            'duration' => $this->duration_label,
            // **المقيسُ من Zoom** — نظير `Meeting::toFullCard()['durationSec']`. و`duration`
            // أعلاه نصٌّ في `duration_label` **بلا كاتبٍ حيّ**: كاتبُه الوحيد يقرأ معامل
            // طلبٍ اسمُه `duration` ولا شاشةَ ترسله، فالعمود لا يتغيّر بعد البذر — ومع ذلك
            // تعرضه الشاشة «مدّة الحضور الفعليّة». و`null` تعني «لم تُقَس» لا صفراً.
            'durationSec' => $this->duration_sec !== null ? (int) $this->duration_sec : null,
            // دورة الحجز/الدفع (تسعير الإدارة → فاتورة → دفع ميسّر → اختيار الموعد)
            'price' => $this->price,
            'vat' => $this->vat,
            'vatRate' => $this->vatRate(),
            'total' => $this->total,
            'priced' => $this->priced_at !== null,
            'paid' => $this->paid_at !== null,
            'paidAgo' => $this->paid_at?->locale('ar')->diffForHumans(),
            'invoiceNo' => $this->invoice?->number,
            // **القرارات تتبع الملخّص في الحجب.** استُخرجت منه بـ`meeting.decisions`
            // (`FinalizeConsultJob`)، فكانت تصل العميل بينما مصدرُها محجوبٌ فوقها
            // بسطرين بانتظار اعتماد محامٍ — يقرأ التزاماتٍ استنبطها نموذج من رأيٍ
            // لم يُقرّه أحد. والنظير `Meeting::toClientCard` يحرسها بـ`$approved`.
            'decisions' => $this->summaryApproved() ? ($this->decisions ?? []) : [],
            'startsAt' => $this->starts_at?->toIso8601String(),
            // ملاحظة: لا يُكشف للعميل رابط التسجيل ولا أنّ الجلسة مُسجّلة — داخلي للمكتب فقط
        ];
    }

    // الشكل الذي تتوقعه الواجهة (ConsultCard في lib/consult-ui.tsx)
    /**
     * **نافذة بدء الجلسة — مصدرٌ واحد.** (قبل الموعد بـ١٥د فأقرب)
     *
     * كان الزرّ ظاهراً لاستشارةٍ بعد ثلاثة أسابيع أو فائتةٍ منذ شهر. واستُخرج من
     * `toCard()` لأن البثّ يحتاجه، ولأن الخادم يجب أن **يفرض** ما تُعلنه البطاقة:
     * حقلٌ يُرسَل ولا يُفرَض توصيةٌ تتجاهلها الواجهة لا قيد.
     *
     * و`starts_at === null` يبقى مسموحاً: استشارةٌ بلا موعد تُبدأ يدوياً — وإسقاط
     * هذا الفرع يُعطّل كلّ استشارةٍ لم يُحدَّد موعدها.
     */
    public function isStartable(): bool
    {
        return ! $this->isMissed()
            // **النهاية لا تُبدأ.** `cancelRequest` يكتب «ملغاة» ولا يمسّ `session`،
            // فتبقى «بانتظار الجلسة» و`starts_at` فارغاً (الإلغاء لما قبل الجلسة)
            // — والفرع الذي يسمح بالبدء بلا موعد كان يجعلها **قابلةً للبدء**، فيصل
            // العميلَ «بدأت جلسة استشارتك» لطلبٍ ألغاه المكتب.
            && ! in_array($this->status, self::CLOSED_STATUSES, true)
            // **ولا تُبدأ جلسةُ طلبٍ في دورة الحجز** — لم يُسعَّر أو يُدفع أو يُنشر موعده (ع٤)
            && ConsultStatus::tryFrom((string) $this->status)?->isPreSession() !== true
            && $this->session === 'بانتظار الجلسة'
            && ($this->starts_at === null || now()->greaterThanOrEqualTo($this->starts_at->copy()->subMinutes(15)));
    }

    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'ref' => $this->ref,
            'client' => $this->user?->name ?? '—',
            'subject' => $this->subject,
            // وقائع العميل كما كتبها عند الحجز — للمسعّر والمحامي (لا تُدمج في الموضوع)
            'details' => $this->details,
            'specialty' => $this->specialty ?? $this->type ?? '',
            'channel' => $this->channel,
            'lawyer' => $this->lawyer,
            /*
             * **معرّف المحامي — لتمييز النائب من الشخص.**
             *
             * `ConsultBooking::resolveContext` يضمن قيمةً نصّيّة دائماً
             * (`?: 'المستشار القانوني'`)، فشرط `!lawyer` في الواجهة **لا يتحقّق أبداً**:
             * زرّ «+ إسناد محامٍ» لا يظهر، ومؤشّر «بانتظار إسناد» ينكمش، والنائب يظهر
             * كاسم شخصٍ في مرشّح المستشارين وله شريط حملٍ في «أحمال المحامين».
             * والمعيار الصادق هو الإسناد نفسه لا نصُّه.
             */
            'lawyerId' => $this->assigned_lawyer_id,
            'when' => $this->whenLabel(),
            'place' => $this->placeForCard(),
            'phone' => $this->phone ?? '',
            'canJoin' => $this->canJoin(),
            // رابط غرفة المنصّة الداخليّ (`joinLink`) — الطريق الوحيد إلى الجلسة
            'slink' => $this->channel === 'مرئية' ? $this->joinLink() : '',
            // لا `hostLink`: رابط المضيف (`start_url`) لا يغادر الخادم — الدخول من غرفة المنصّة وحدها (قرار المالك 2026-09-29)
            'session' => $this->session,
            'missed' => $this->isMissed(), // فات موعدها بلا جلسة — تبويب «فائتة» وإجراءا لم يحضر/إعادة الجدولة
            'notHeld' => $this->isNotHeld(), // سُجّلت «لم تُعقد» — العلمان نفساهما في بطاقة العميل
            // لونا الشارتين من الـEnum (`ConsultStatus::tone` · `SessionState::tone`) — لا خريطة في الواجهة
            'tone' => $this->statusTone(),
            'sessionTone' => $this->sessionTone(),
            // **مجموعات الحالة أعلامٌ من الخادم** — كانت الشاشات تنسخ قوائمها (`CONSULT_TERMINAL_STATUSES`…)
            'isTerminal' => in_array($this->status, self::TERMINAL_STATUSES, true),
            'isClosed' => in_array($this->status, self::CLOSED_STATUSES, true),
            'sessionEnded' => in_array($this->session, self::SESSION_ENDED, true),
            // ذاكرة إعادة الجدولة: كم مرّة أُعيدت (السقف في `reschedule.limit` المشترك)، وطلب العميل المعلّق
            'rescheduleCount' => (int) $this->reschedule_count,
            // **هل تُعاد جدولتها الآن؟ — من حارس الانتقال نفسه** لا من تخمين الواجهة. كان الزرّ في
            // درج المحامي واستقبال الإدارة للفائتة وحدها، والخادم يقبل كلّ موعدٍ لم ينعقد: فلم يجد
            // المحامي المعتذر عن موعد الأسبوع القادم زرّاً. والسقف يُفحص عند الإرسال بفاعله.
            'canReschedule' => (new RescheduleConsult)->guard($this, []) === null,
            'canMarkNoShow' => $this->canMarkNoShow(),
            'canApproveSummary' => $this->summaryApprovalBlocker() === null,
            // **أعلامُ الإجراءات من الخادم** — كانت شاشة الإدارة تقارن نصّ الحالة لتقرّر أيّ زرٍّ يظهر
            // (التسعير · التذكير · اعتماد التحليل)؛ والشرط الآن من الكتالوج والحارس اللذين يحكمان الطلب
            'needsPricing' => $this->status === ConsultStatus::AwaitingPricing->value,
            'canRemindSchedule' => $this->status === ConsultStatus::AwaitingSchedule->value,
            'canApproveAnalysis' => (new ApproveConsultAnalysis)->accepts((string) $this->status),
            // مرحلة دورة الحجز (التسعير ← السداد ← الموعد ← اعتماده) — مفتاحٌ ثابت تُجمَّع به شاشة
            // «طلبات الاستشارات» بدل مقارنة أربعة نصوص عربيّة؛ و`null` لما تجاوز دورة الحجز
            'bookingStage' => match ($this->status) {
                ConsultStatus::AwaitingPricing->value => 'pricing',
                ConsultStatus::AwaitingPayment->value => 'payment',
                ConsultStatus::AwaitingSchedule->value => 'scheduling',
                ConsultStatus::AwaitingAppointmentApproval->value => 'approval',
                default => null,
            },
            'clientRescheduleRequest' => $this->reschedule_requested_at === null ? null : [
                'at' => $this->reschedule_requested_at->toIso8601String(),
                'note' => $this->reschedule_request_note,
            ],
            'startable' => $this->isStartable(),
            'startsAt' => $this->starts_at?->toIso8601String(),
            // اقتراح الموظّف بانتظار اعتماد الإدارة — تعرضه شاشة الطلبات لتعتمده أو تعدّله
            'proposal' => $this->status === 'بانتظار اعتماد الموعد' && $this->appointment?->status === 'بانتظار الاعتماد' ? [
                'date' => $this->appointment->starts_at?->format('Y-m-d'),
                'time' => $this->appointment->starts_at?->format('H:i'),
                'lawyer' => $this->appointment->lawyer,
                'lawyerId' => $this->appointment->lawyer_id,
                'channel' => str_replace('استشارة ', '', (string) $this->appointment->type),
            ] : null,
            'status' => $this->status,
            /*
             * **سببُ تعذّر الإسناد الآن بحسب المرحلة الفعليّة — من الخادم** (ملاحظة المالك 2026-09-27).
             * كانت النافذة تقارن النصّ بقائمة أربع حالات وتقول «لا إسناد إلا بعد اكتمال التسعير والسداد
             * وتحديد الموعد» لعميلٍ دفع، وشارةُ «المسند: …» تعرض المحامي المنقول من التذكرة عند الطلب
             * كأنّه أُسند. والإسناد في دورة الحجز يتمّ **مع اعتماد الموعد** (`PublishAppointment`).
             */
            'assignBlocker' => match ($this->status) {
                ConsultStatus::AwaitingPricing->value => 'بانتظار تسعير الإدارة — يُسند المحامي مع اعتماد الموعد.',
                ConsultStatus::AwaitingPayment->value => 'سُعّرت الاستشارة وبانتظار سداد العميل — يُسند المحامي مع اعتماد الموعد.',
                ConsultStatus::AwaitingSchedule->value => 'سدّد العميل ✓ — بانتظار تحديد الموعد، ويُسند المحامي مع اعتماده.',
                ConsultStatus::AwaitingAppointmentApproval->value => 'سدّد العميل ✓ — الموعد المقترح بانتظار اعتماد الإدارة، ويُسند المحامي عند اعتماده.',
                default => null,
            },
            // في دورة الحجز المحامي **مرشَّحٌ** من التذكرة لا مُسنَد
            'lawyerTentative' => ConsultStatus::tryFrom((string) $this->status)?->isPreSession() === true,
            // الموعد المقترح بانتظار الاعتماد — `when` يبقى فارغاً حتى يُنشر فيُقرأ «لم يحدّد»
            'proposedWhen' => $this->starts_at === null && $this->appointment?->starts_at
                ? $this->appointment->starts_at->locale('ar')->translatedFormat('l d F Y · h:i A')
                : null,
            'summary' => $this->summary,
            // الطاقم يرى النصّ قبل الاعتماد ليراجعه — ويرى **أنّه** غير معتمَد
            'summaryApproved' => $this->summaryApproved(),
            // اعتمده المحامي ويُنتظر اعتماد الإدارة (قرار المالك 2026-09-14)
            'summaryLawyerApproved' => $this->summary_lawyer_approved_at !== null,
            'summaryPending' => $this->summary !== null && ! $this->summaryApproved(),
            'summaryEdited' => $this->summary_edited_at !== null,
            'summaryApprovedAt' => $this->summary_approved_at?->toIso8601String(),
            // **مصدرُ الملخّص غيرُ مصدرِ التحليل.** أُنشئ العمودان منفصلين لأن تعثّر
            // الملخّص كان يدهس `ai_source` فيُعيد وسم تحليلٍ سابق **نجح** بأنه فاشل.
            // ثمّ فُصلا في الخادم ولم يصل الثاني الواجهة قطّ — فصار تعثّر الملخّص
            // **غير مرئيّ تماماً**: لا شارة ولا عنوان. و`null` تعني «لم يُقَس»
            // (صفوف ما قبل الهجرة) لا «نجح».
            'summaryAiSource' => $this->summary_ai_source,
            // مادّة Zoom مفصولة عن الملخّص — تُعرض للطاقم ليبني عليها تحريره
            'zoomSummary' => $this->zoom_summary,
            // **رقم التذكرة لا معرّفها:** زرّ «تحويل إلى قضية» في شاشة المحامي
            // كان يقرأ `ticket_id` ولا تُرسله البطاقة، فبقي **معطّلاً دائماً**.
            // والمسار `tickets/{ticket}/convert` يربط بـ`number` (`getRouteKeyName`)،
            // فتمرير المعرّف الرقميّ كان سيُعطي ٤٠٤ لو أُرسل.
            'ticketNo' => $this->ticket?->number,
            /*
             * **رقم القضيّة إن تحوّلت — إشارةٌ حقيقيّة بدل حالةٍ ميتة.**
             *
             * كانت الشاشات تعدّ `status === 'محولة إلى قضية'` ولا يكتبها أيّ مسار،
             * فمؤشّر «استشارة أصبحت قضية» ونسبتُه صفرٌ أبداً في لوحة الإدارة. والتحويل
             * يقع على **التذكرة** لا الاستشارة، فالإشارة الصادقة وجودُ قضيّةٍ لتذكرتها.
             */
            'caseNo' => $this->ticket?->legalCase?->number,
            // مقترح المآل من بطاقة التذكرة **بعد الجلسة** — القاعدة نفسها في `OutcomeSummaryGate::consultBlocker`
            'canProposeOutcome' => $this->ticket !== null
                && $this->status === ConsultStatus::Ended->value
                && $this->ticket->legalCase === null,
            // تدوين الجلسة — درج المحامي يملأ حقله منها؛ وكان يقرأ `notes`
            // التي لا تُرسل، فيفتح المحامي الدرج فيرى حقلاً فارغاً وتدوينه محفوظ.
            // (داخليّة للمكتب — لا وجود لها في `toClientCard`.)
            'sessionNotes' => $this->session_notes,
            'duration' => $this->duration_label,
            // **المقيسُ من Zoom** — نظير `Meeting::toFullCard()['durationSec']`. و`duration`
            // أعلاه نصٌّ في `duration_label` **بلا كاتبٍ حيّ**: كاتبُه الوحيد يقرأ معامل
            // طلبٍ اسمُه `duration` ولا شاشةَ ترسله، فالعمود لا يتغيّر بعد البذر — ومع ذلك
            // تعرضه الشاشة «مدّة الحضور الفعليّة». و`null` تعني «لم تُقَس» لا صفراً.
            'durationSec' => $this->duration_sec !== null ? (int) $this->duration_sec : null,
            /*
             * **مخرجات الجلسة أعلامٌ لا روابط** (قرار المالك 2026-09-15): الأزرار تشغّل وتنزّل
             * عبر مسارات المكتب الداخليّة، ولا يصل المتصفّحَ رابطُ سحابة Zoom. وبطاقة العميل
             * لا تحملها — العميل لا يرى تسجيل استشارته.
             */
            'media' => RecordingArchive::availability($this),
            'price' => $this->price,
            'vat' => $this->vat,
            'vatRate' => $this->vatRate(),
            'total' => $this->total,
            'priced' => $this->priced_at !== null,
            'paid' => $this->paid_at !== null,
            'paidAgo' => $this->paid_at?->locale('ar')->diffForHumans(),
            'invoiceNo' => $this->invoice?->number,
            // رحلة المعالجة (يطابق واجهة Consult في employee-data)
            'type' => $this->type ?? 'عام',
            'priority' => $this->priority ?? 'متوسطة',
            // «الآن» المخزّنة كانت تتجمّد للأبد — الاشتقاق الحيّ من وقت الإنشاء (العمود يبقى للتوافق)
            'received' => $this->created_at?->locale('ar')->diffForHumans() ?? ($this->received_label ?: $this->when_label),
            'employee' => $this->employee ?: '—',
            /*
             * **عمرُ الطلب مقيسٌ لا مخزَّن.**
             *
             * كان `mins` عموداً **بلا كاتبٍ في `app/` كلّه**: افتراضيّه صفر في الهجرة ثمّ
             * `?? 0` هنا — فيصل الواجهةَ صفراً دائماً، وخمسةُ مؤشّرات تبنيه عليه:
             * «متأخرة» بشريطه الأحمر، وتبويبٌ يُفرغ الجدول، و«متوسط الزمن»، و«منذ {n}
             * دقيقة». والصفر كذبةٌ مضاعفة: يُقرأ «قِيس فوجد صفراً» لا «لم يُقَس».
             *
             * و`created_at` مكتوبٌ لكلّ صفّ — فالعمر يُشتقّ منه حيّاً، و`null` حين لا
             * سبيل إلى القياس.
             */
            'ageMins' => $this->created_at === null ? null : (int) $this->created_at->diffInMinutes(now()),
            'aiDone' => (bool) $this->ai_done,
            // مصدر المخرج: '' = غير معروف (صفوف ما قبل الهجرة)
            'aiSource' => $this->ai_source ?? '',
            'aiClass' => $this->ai_class ?? '',
            'aiSummary' => $this->ai_summary ?? '',
            'aiLawyer' => $this->ai_lawyer ?? '',
            'missing' => $this->missing ?? [],
            'audit' => $this->audit ?? [],
            'decisions' => $this->decisions ?? [],
            'tasksCreated' => (bool) $this->tasks_created,
            'zoomUuid' => $this->zoom_uuid,
            'zoomParticipantsLog' => $this->zoom_participants_log ?? [],
            'zoomAiNextSteps' => $this->zoom_ai_next_steps ?? [],
        ];
    }

    // يضيف قيداً في سجل التدقيق (الأحدث أولاً) — على المستدعي الحفظ
    public function logAudit(string $user, string $field, string $before, string $after): void
    {
        /*
         * طابع حقيقي — «الآن» النصية كانت تجعل كل قيود التدقيق تقول «الآن» للأبد.
         *
         * **و`h` بلا ص/م تلتبس:** ٠٠:٤٧ تُعرض «12:47» فتُقرأ ظهراً، ويبدو ترتيب السجلّ
         * مقلوباً وهو سليم — قِيس ذلك على سجلٍّ حقيقيّ في درج 360°. والمشروع يعرف
         * الصواب: ستّة مواضع تلحق ص/م، منها `Concerns\UsesClock`.
         */
        $now = now();
        $stamp = $now->format('Y/m/d h:i').' '.($now->hour < 12 ? 'ص' : 'م');
        // `time` للعرض بصيغة ١٢ ساعة، و`at` للفرز: الأولى لا تُفرز لفظياً
        // («١١:٠٠ ص» تسبق «٠١:٠٠ م» حرفياً وهي بعدها زمنياً) — وشاشة الإدارة
        // كانت تفرز عليها فتعرض «أحدث عشرة» بترتيبٍ مقلوب.
        $entry = ['user' => $user, 'field' => $field, 'before' => $before, 'after' => $after, 'time' => $stamp, 'at' => $now->toIso8601String()];
        $this->audit = array_merge([$entry], $this->audit ?? []);
    }

    /** تحليل الطلب وملخّص الجلسة وملاحظاتها — نسخٌ على الاستشارة نفسها (`ContentRevisions`). */
    public function revisionKinds(): array
    {
        return [
            'consult_analysis' => ['ai_class', 'ai_summary', 'ai_lawyer'],
            'consult_summary' => ['summary'],
            'consult_notes' => ['session_notes'],
        ];
    }

    public function revisionOwner(): ?Model
    {
        return $this;
    }
}
