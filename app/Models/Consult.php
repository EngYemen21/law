<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * سجلّ الاستشارة (CN-2026-####) — يُنشأ عند تأكيد حجز الاستشارة من التذكرة،
 * ويحمل جلستها (بانتظار الجلسة → جلسة جارية → منتهية) وملخصها.
 */
class Consult extends Model
{
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
        // **`'مؤكد'` مطويّة — تُكتب على `Appointment` لا على `Consult`.**
        // `ConsultBooking` يكتبها على الموعد المرافق، والاستشارة تُضبط «جديدة». فكانت
        // حالةً ميتةً رابعة يقارنها عمودٌ في الكانبان ويحملها صفٌّ مبذور وحده. ونظيرتها
        // `'بانتظار التأكيد'` مطويّةٌ في `Staff\MeetingController` للعلّة نفسها.
        // 'مؤكد',
        // رحلة المعالجة (`Staff\ConsultController`)
        'جديدة',
        'قيد مراجعة الموظف',
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

    public const PRE_SESSION_STATUSES = ['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد'];

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

    protected $fillable = [
        'user_id', 'ticket_id', 'appointment_id', 'ref', 'subject', 'type', 'priority', 'channel',
        'lawyer', 'assigned_lawyer_id', 'specialty', 'employee', 'day', 'time', 'when_label', 'received_label', 'phone',
        'starts_at', 'duration_min',
        'meet_id', 'meet_link', 'host_link', 'meet_password',
        'link_released_at', 'reminder_24h_sent_at', 'reminder_30m_sent_at', 'join_time', 'leave_time', 'duration_sec', 'transcript', 'recording_url', 'transcript_path', 'zoom_summary_at',
        'zoom_uuid', 'zoom_share_url', 'zoom_audio_url', 'zoom_participants_log', 'zoom_ai_next_steps',
        'status', 'session', 'session_notes', 'summary', 'summary_ai_original', 'summary_approved_at', 'summary_approved_by',
        'summary_edited_at', 'summary_edited_by', 'summary_ai_source', 'session_finalized_at', 'zoom_summary', 'duration_label',
        'decisions', 'tasks_created', 'suggested_tasks',
        'price', 'vat', 'total', 'mins', 'priced_at', 'paid_at',
        'ai_done', 'ai_source', 'ai_class', 'ai_summary', 'ai_lawyer', 'missing', 'audit',
        'google_event_id',
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
        'summary_edited_at' => 'datetime',
        'session_finalized_at' => 'datetime',
        'paid_at' => 'datetime',
        'link_released_at' => 'datetime',
        'reminder_24h_sent_at' => 'datetime',
        'reminder_30m_sent_at' => 'datetime',
        'join_time' => 'datetime',
        'leave_time' => 'datetime',
        'zoom_summary_at' => 'datetime',
    ];

    /**
     * هل يُفعَّل زر «الدخول إلى الجلسة»؟ للمرئية فقط، بعد إطلاق الرابط (قبل الموعد بـ5د)،
     * وقبل انتهاء الجلسة. قبل الإطلاق يكون الزر معطّلاً تماماً.
     * سقف علوي: كان الشرط بلا حدّ زمني فيبقى الزر مفعّلاً للأبد بعد فوات موعد لم تُعقد جلسته.
     */
    public function canJoin(): bool
    {
        if ($this->channel !== 'مرئية' || $this->session === 'منتهية') {
            return false;
        }

        // جلسة جارية فعلاً: الدخول متاح ضمن سقف (المدة + 180د) — لا «جارية» أبدية
        if ($this->session === 'جلسة جارية') {
            return $this->starts_at === null
                || $this->starts_at->copy()->addMinutes(($this->duration_min ?: 45) + 180)->isFuture();
        }

        if ($this->link_released_at === null) {
            return false;
        }

        // أُطلق الرابط: نافذة مغلقة حتى (الموعد + المدة + 30د)
        return $this->starts_at === null
            || $this->starts_at->copy()->addMinutes(($this->duration_min ?: 45) + 30)->isFuture();
    }

    /** فاتت نافذة موعدها (البداية + المدة) ولم تُعقد جلستها — لا تُعرض «بانتظار الجلسة» للأبد */
    public function isMissed(): bool
    {
        return $this->session === 'بانتظار الجلسة'
            && $this->starts_at !== null
            && $this->starts_at->copy()->addMinutes($this->duration_min ?: 45)->isPast();
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
     * مكان/قناة الجلسة المعروض — مصدر واحد بعد إزالة كيان «الفرع».
     * المرئية والهاتفية قناتان لا مكان لهما، والحضورية تأخذ مكان موعدها المقترن
     * (ConsultBooking يكتبه على الموعد)، وإلا عنوان المكتب من الإعدادات.
     */
    public function placeLabel(): string
    {
        return match ($this->channel) {
            'مرئية' => 'اجتماع إلكتروني',
            'هاتفية' => 'مكالمة هاتفية',
            default => $this->appointment?->place ?: (string) config('office.address'),
        };
    }

    /** المكان للعرض في البطاقات — فارغ ما لم يُحجز موعد بعد. */
    public function placeForCard(): string
    {
        return $this->appointment_id ? $this->placeLabel() : '';
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
    /** هل اعتمد إنسانٌ مفوَّض ملخّص هذه الاستشارة؟ */
    public function summaryApproved(): bool
    {
        return $this->summary_approved_at !== null;
    }

    public function toClientCard(): array
    {
        return [
            'id' => $this->id,
            'ref' => $this->ref,
            'subject' => $this->subject,
            'specialty' => $this->specialty ?? '',
            'channel' => $this->channel,
            'lawyer' => $this->lawyer,
            'when' => $this->whenLabel(),
            'place' => $this->placeForCard(),
            'slink' => $this->channel === 'مرئية' ? $this->joinLink() : '',
            'canJoin' => $this->canJoin(), // زر الدخول معطّل حتى إطلاق الرابط قبل الموعد بـ5د
            'missed' => $this->isMissed(), // فات موعدها بلا جلسة — كانت «بانتظار الجلسة» أبدية متناقضة مع «لم يحضر» في المواعيد
            'session' => $this->session,
            'status' => $this->status,
            // **لا رأي قانونيّ يصل العميل قبل أن يعتمده محامٍ.** كان الملخّص يُكتب
            // بالنموذج ويُعرض فوراً تحت شارة «معتمد رسمياً» بلا مرور إنسان به.
            // والحجب هنا لا في الواجهة: حجبٌ واجهيّ يبقى النصّ فيه في حمولة
            // المتصفّح، فيُقرأ بأدوات المطوّر ويصل من لا يجوز أن يصله.
            'summary' => $this->summaryApproved() ? $this->summary : null,
            'summaryPending' => $this->summary !== null && ! $this->summaryApproved(),
            'summaryApproved' => $this->summaryApproved(),
            'duration' => $this->duration_label,
            // دورة الحجز/الدفع (تسعير الإدارة → فاتورة → دفع ميسّر → اختيار الموعد)
            'price' => $this->price,
            'vat' => $this->vat,
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
            // رابط اجتماع Zoom الحقيقي؛ وعند غيابه (لم تُهيّأ مفاتيح Zoom بعد) الرابط الداخلي الاحتياطي
            'slink' => $this->channel === 'مرئية' ? $this->joinLink() : '',
            'hostLink' => $this->channel === 'مرئية' ? ($this->host_link ?: null) : null,
            'session' => $this->session,
            'missed' => $this->isMissed(), // فات موعدها بلا جلسة — تبويب «فائتة» وإجراءا لم يحضر/إعادة الجدولة
            'startable' => $this->isStartable(),
            'startsAt' => $this->starts_at?->toIso8601String(),
            'status' => $this->status,
            'summary' => $this->summary,
            // الطاقم يرى النصّ قبل الاعتماد ليراجعه — ويرى **أنّه** غير معتمَد
            'summaryApproved' => $this->summaryApproved(),
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
            // تدوين الجلسة — درج المحامي يملأ حقله منها؛ وكان يقرأ `notes`
            // التي لا تُرسل، فيفتح المحامي الدرج فيرى حقلاً فارغاً وتدوينه محفوظ.
            // (داخليّة للمكتب — لا وجود لها في `toClientCard`.)
            'sessionNotes' => $this->session_notes,
            'duration' => $this->duration_label,
            'recording' => $this->recording_url,
            'price' => $this->price,
            'vat' => $this->vat,
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
            'zoomShareUrl' => $this->zoom_share_url,
            'zoomAudioUrl' => $this->zoom_audio_url,
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
        $entry = ['user' => $user, 'field' => $field, 'before' => $before, 'after' => $after, 'time' => $stamp];
        $this->audit = array_merge([$entry], $this->audit ?? []);
    }
}
