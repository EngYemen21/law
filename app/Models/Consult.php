<?php

namespace App\Models;

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
        'lawyer', 'assigned_lawyer_id', 'specialty', 'employee', 'day', 'time', 'when_label', 'received_label', 'branch', 'phone',
        'starts_at', 'duration_min',
        'meet_id', 'meet_link', 'host_link', 'meet_password',
        'link_released_at', 'reminder_24h_sent_at', 'reminder_1h_sent_at', 'join_time', 'leave_time', 'duration_sec', 'transcript', 'recording_url', 'transcript_path', 'zoom_summary_at',
        'zoom_uuid', 'zoom_share_url', 'zoom_audio_url', 'zoom_participants_log', 'zoom_ai_next_steps',
        'status', 'session', 'session_notes', 'summary', 'duration_label',
        'decisions', 'tasks_created',
        'price', 'vat', 'total', 'mins', 'priced_at', 'paid_at',
        'ai_done', 'ai_class', 'ai_summary', 'ai_lawyer', 'missing', 'audit',
    ];

    protected $casts = [
        'ai_done' => 'boolean',
        'tasks_created' => 'boolean',
        'missing' => 'array',
        'audit' => 'array',
        'decisions' => 'array',
        'zoom_participants_log' => 'array',
        'zoom_ai_next_steps' => 'array',
        'starts_at' => 'datetime',
        'priced_at' => 'datetime',
        'paid_at' => 'datetime',
        'link_released_at' => 'datetime',
        'reminder_24h_sent_at' => 'datetime',
        'reminder_1h_sent_at' => 'datetime',
        'join_time' => 'datetime',
        'leave_time' => 'datetime',
        'zoom_summary_at' => 'datetime',
    ];

    /**
     * هل يُفعَّل زر «الدخول إلى الجلسة»؟ للمرئية فقط، بعد إطلاق الرابط (قبل الموعد بـ5د)،
     * وقبل انتهاء الجلسة. قبل الإطلاق يكون الزر معطّلاً تماماً.
     */
    public function canJoin(): bool
    {
        return $this->channel === 'مرئية'
            && $this->session !== 'منتهية'
            // يُفعَّل بإطلاق الرابط قبل الموعد بـ5د، أو فور بدء المحامي للجلسة (جارية الآن)
            && ($this->link_released_at !== null || $this->session === 'جلسة جارية');
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

    // رابط انضمام الجلسة المرئية (join_url من Zoom)؛ وعند غيابه الرابط الاحتياطي الموحّد.
    public function joinLink(): string
    {
        return $this->meet_link ?: config('services.zoom.fallback_base').$this->ref;
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
            'branch' => $this->branch ?? '',
            'slink' => $this->channel === 'مرئية' ? $this->joinLink() : '',
            'canJoin' => $this->canJoin(), // زر الدخول معطّل حتى إطلاق الرابط قبل الموعد بـ5د
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
            'invoiceNo' => $this->invoice?->number,
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
            'channel' => $this->channel,
            'lawyer' => $this->lawyer,
            'when' => $this->whenLabel(),
            'branch' => $this->branch ?? '',
            'phone' => $this->phone ?? '',
            // رابط اجتماع Zoom الحقيقي؛ وعند غيابه (لم تُهيّأ مفاتيح Zoom بعد) الرابط الداخلي الاحتياطي
            'slink' => $this->channel === 'مرئية' ? $this->joinLink() : '',
            'hostLink' => $this->channel === 'مرئية' ? ($this->host_link ?: null) : null,
            'session' => $this->session,
            'status' => $this->status,
            'summary' => $this->summary,
            'duration' => $this->duration_label,
            'recording' => $this->recording_url,
            'price' => $this->price,
            'vat' => $this->vat,
            'total' => $this->total,
            'priced' => $this->priced_at !== null,
            'paid' => $this->paid_at !== null,
            'invoiceNo' => $this->invoice?->number,
            // رحلة المعالجة (يطابق واجهة Consult في employee-data)
            'type' => $this->type ?? 'عام',
            'priority' => $this->priority ?? 'متوسطة',
            'received' => $this->received_label ?: $this->when_label,
            'employee' => $this->employee ?: '—',
            'mins' => $this->mins ?? 0,
            'aiDone' => (bool) $this->ai_done,
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
        $entry = ['user' => $user, 'field' => $field, 'before' => $before, 'after' => $after, 'time' => 'الآن'];
        $this->audit = array_merge([$entry], $this->audit ?? []);
    }
}
