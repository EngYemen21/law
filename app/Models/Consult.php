<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجلّ الاستشارة (CN-2026-####) — يُنشأ عند تأكيد حجز الاستشارة من التذكرة،
 * ويحمل جلستها (بانتظار الجلسة → جلسة جارية → منتهية) وملخصها.
 */
class Consult extends Model
{
    protected $fillable = [
        'user_id', 'ticket_id', 'appointment_id', 'ref', 'subject', 'type', 'priority', 'channel',
        'lawyer', 'employee', 'day', 'time', 'when_label', 'received_label', 'branch', 'phone',
        'meet_id', 'meet_link', 'host_link',
        'status', 'session', 'session_notes', 'summary', 'duration_label',
        'decisions', 'tasks_created',
        'price', 'vat', 'total', 'mins',
        'ai_done', 'ai_class', 'ai_summary', 'ai_lawyer', 'missing', 'audit',
    ];

    protected $casts = [
        'ai_done' => 'boolean',
        'tasks_created' => 'boolean',
        'missing' => 'array',
        'audit' => 'array',
        'decisions' => 'array',
    ];

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

    // رابط انضمام الجلسة المرئية (join_url من Zoom)؛ وعند غيابه الرابط الاحتياطي الموحّد.
    public function joinLink(): string
    {
        return $this->meet_link ?: config('services.zoom.fallback_base').$this->ref;
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
            'channel' => $this->channel,
            'lawyer' => $this->lawyer,
            'when' => $this->when_label,
            'branch' => $this->branch ?? '',
            'slink' => $this->channel === 'مرئية' ? $this->joinLink() : '',
            'session' => $this->session,
            'status' => $this->status,
            'summary' => $this->summary,
            'duration' => $this->duration_label,
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
            'when' => $this->when_label,
            'branch' => $this->branch ?? '',
            'phone' => $this->phone ?? '',
            // رابط اجتماع Zoom الحقيقي؛ وعند غيابه (لم تُهيّأ مفاتيح Zoom بعد) الرابط الداخلي الاحتياطي
            'slink' => $this->channel === 'مرئية' ? $this->joinLink() : '',
            'hostLink' => $this->channel === 'مرئية' ? ($this->host_link ?: null) : null,
            'session' => $this->session,
            'status' => $this->status,
            'summary' => $this->summary,
            'duration' => $this->duration_label,
            'total' => $this->total,
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
        ];
    }

    // يضيف قيداً في سجل التدقيق (الأحدث أولاً) — على المستدعي الحفظ
    public function logAudit(string $user, string $field, string $before, string $after): void
    {
        $entry = ['user' => $user, 'field' => $field, 'before' => $before, 'after' => $after, 'time' => 'الآن'];
        $this->audit = array_merge([$entry], $this->audit ?? []);
    }
}
