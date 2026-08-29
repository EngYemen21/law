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
    public const PRE_SESSION_STATUSES = ['بانتظار التسعير', 'بانتظار السداد', 'بانتظار تحديد الموعد'];

    protected $fillable = [
        'user_id', 'ticket_id', 'appointment_id', 'ref', 'subject', 'type', 'priority', 'channel',
        'lawyer', 'assigned_lawyer_id', 'specialty', 'employee', 'day', 'time', 'when_label', 'received_label', 'phone',
        'starts_at', 'duration_min',
        'meet_id', 'meet_link', 'host_link', 'meet_password',
        'link_released_at', 'reminder_24h_sent_at', 'reminder_30m_sent_at', 'join_time', 'leave_time', 'duration_sec', 'transcript', 'recording_url', 'transcript_path', 'zoom_summary_at',
        'zoom_uuid', 'zoom_share_url', 'zoom_audio_url', 'zoom_participants_log', 'zoom_ai_next_steps',
        'status', 'session', 'session_notes', 'summary', 'duration_label',
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
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
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
            'summary' => $this->summary,
            'duration' => $this->duration_label,
            // دورة الحجز/الدفع (تسعير الإدارة → فاتورة → دفع ميسّر → اختيار الموعد)
            'price' => $this->price,
            'vat' => $this->vat,
            'total' => $this->total,
            'priced' => $this->priced_at !== null,
            'paid' => $this->paid_at !== null,
            'paidAgo' => $this->paid_at?->locale('ar')->diffForHumans(),
            'invoiceNo' => $this->invoice?->number,
            'decisions' => $this->decisions ?? [],
            'startsAt' => $this->starts_at?->toIso8601String(),
            // ملاحظة: لا يُكشف للعميل رابط التسجيل ولا أنّ الجلسة مُسجّلة — داخلي للمكتب فقط
        ];
    }

    // الشكل الذي تتوقعه الواجهة (ConsultCard في lib/consult-ui.tsx)
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
            'when' => $this->whenLabel(),
            'place' => $this->placeForCard(),
            'phone' => $this->phone ?? '',
            'canJoin' => $this->canJoin(),
            // رابط اجتماع Zoom الحقيقي؛ وعند غيابه (لم تُهيّأ مفاتيح Zoom بعد) الرابط الداخلي الاحتياطي
            'slink' => $this->channel === 'مرئية' ? $this->joinLink() : '',
            'hostLink' => $this->channel === 'مرئية' ? ($this->host_link ?: null) : null,
            'session' => $this->session,
            'missed' => $this->isMissed(), // فات موعدها بلا جلسة — تبويب «فائتة» وإجراءا لم يحضر/إعادة الجدولة
            // «بدء الجلسة» ضمن نافذة الموعد فقط (قبل 15د فأقرب) — كان الزر ظاهراً لاستشارة بعد 3 أسابيع أو فائتة منذ شهر
            'startable' => ! $this->isMissed()
                && $this->session === 'بانتظار الجلسة'
                && ($this->starts_at === null || now()->greaterThanOrEqualTo($this->starts_at->copy()->subMinutes(15))),
            'startsAt' => $this->starts_at?->toIso8601String(),
            'status' => $this->status,
            'summary' => $this->summary,
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
            'mins' => $this->mins ?? 0,
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
        // طابع حقيقي — «الآن» النصية كانت تجعل كل قيود التدقيق تقول «الآن» للأبد
        $entry = ['user' => $user, 'field' => $field, 'before' => $before, 'after' => $after, 'time' => now()->format('Y/m/d h:i')];
        $this->audit = array_merge([$entry], $this->audit ?? []);
    }
}
