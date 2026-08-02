<?php

namespace App\Models;

use App\Models\Concerns\HasBranch;
use App\Support\CorrFlow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مخاطبة رسميّة مع جهة/محكمة عبر النظام الخارجيّ — دورة 7 مراحل + إفادة العميل + ربط بالتنفيذ/القضايا.
 * نظير Execution في النمط (HasBranch + رقم كمفتاح مسار + toCard/toClientCard).
 */
class Correspondence extends Model
{
    use HasBranch;

    protected $fillable = [
        'number', 'user_id', 'assigned_lawyer_id', 'lawyer', 'branch', 'case_id', 'execution_id',
        'direction', 'entity', 'subject', 'channel', 'body',
        'stage', 'status', 'tone', 'date_label', 'due_label',
        'ext_ref', 'ext_status', 'ext_synced_at', 'reply_body',
        'briefed', 'brief_note', 'brief_requested', 'audit',
    ];

    protected $casts = [
        'stage' => 'integer',
        'briefed' => 'boolean',
        'brief_requested' => 'boolean',
        'ext_synced_at' => 'datetime',
        'audit' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /** يقيّد إجراءً في سجلّ التدقيق (الأحدث أوّلاً) — نظير Consult::logAudit. */
    public function logAudit(string $action, string $by): void
    {
        $audit = $this->audit ?? [];
        array_unshift($audit, ['a' => $action, 'by' => $by, 't' => now()->format('Y/m/d h:i')]);
        $this->audit = $audit;
    }

    /** بطاقة المكتب (محامي/إدارة) — كلّ التفاصيل. */
    public function toCard(): array
    {
        return [
            'id' => $this->number,
            'dir' => $this->direction,
            'entity' => $this->entity,
            'subject' => $this->subject,
            'channel' => $this->channel ?? '',
            'ref' => $this->case_id ? ($this->legalCase?->number ?? '—') : ($this->execution_id ? ($this->execution?->number ?? '—') : '—'),
            'client' => $this->user?->name ?? '—',
            'lawyer' => $this->lawyer ?? '',
            'stage' => (int) $this->stage,
            'status' => $this->status,
            'tone' => $this->tone,
            'date' => $this->date_label ?? '',
            'due' => $this->due_label ?? '',
            'body' => $this->body ?? '',
            'extRef' => $this->ext_ref ?? '',
            'extStatus' => $this->ext_status ?? '',
            'extSync' => $this->ext_synced_at?->format('Y/m/d h:i') ?? '',
            'reply' => $this->reply_body ?? '',
            'briefed' => (bool) $this->briefed,
            'briefNote' => $this->brief_note ?? '',
            'briefReq' => (bool) $this->brief_requested,
            'execRef' => $this->execution?->number,
            'channelName' => 'corr.'.$this->id,
            'audit' => array_values($this->audit ?? []),
        ];
    }

    /** بطاقة العميل — رحلة مبسّطة + الإفادة فقط (بلا تدقيق/نظام خارجيّ داخليّ). */
    public function toClientCard(): array
    {
        return [
            'id' => $this->number,
            'dir' => $this->direction,
            'entity' => $this->entity,
            'subject' => $this->subject,
            'date' => $this->date_label ?? '',
            'clientStage' => CorrFlow::clientStage((int) $this->stage, (bool) $this->briefed),
            'briefed' => (bool) $this->briefed,
            'briefNote' => $this->brief_note ?? '',
            'briefReq' => (bool) $this->brief_requested,
            // ردّ الجهة يظهر للعميل فقط بعد الإفادة الرسميّة
            'reply' => ($this->briefed && $this->reply_body) ? $this->reply_body : '',
            'channelName' => 'corr.'.$this->id,
        ];
    }
}
