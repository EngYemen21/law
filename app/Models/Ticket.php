<?php

namespace App\Models;

use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    use HasBranch;

    protected $fillable = [
        'user_id', 'number', 'type', 'subject', 'opponent_name', 'opponent_id', 'claim_amount', 'court_name', 'priority',
        'department', 'assigned_lawyer', 'assigned_lawyer_id', 'branch', 'status', 'tone', 'attachments', 'last_message', 'date_label',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function summary(): HasOne
    {
        return $this->hasOne(TicketSummary::class);
    }

    // المستندات المرفوعة مع نتيجة فحصها الذكي
    public function documents(): HasMany
    {
        return $this->hasMany(TicketDocument::class)->orderBy('id');
    }

    // حساب المحامي المسند (المصدر الموثوق؛ العمود النصي للعرض فقط)
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    public function legalCase(): HasOne
    {
        return $this->hasOne(LegalCase::class);
    }

    // استشارات هذه التذكرة (تُنشأ عند طلب حجز استشارة من داخل المحادثة)
    public function consults(): HasMany
    {
        return $this->hasMany(Consult::class);
    }

    // ربط المسار برقم التذكرة بدل المعرّف
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    // الشكل الذي تتوقعه واجهة العميل (يطابق DATA.tickets)
    public function toCard(): array
    {
        return [
            'no' => $this->number,
            'type' => $this->type,
            'subject' => $this->subject,
            'priority' => $this->priority ?: 'متوسطة',
            'dept' => $this->department,
            'status' => $this->status,
            'tone' => $this->tone,
            'last' => $this->last_message,
            'date' => $this->date_label,
        ];
    }

    // الشكل الذي تتوقعه واجهة الموظف (يطابق SYS_TICKETS) — اسم العميل مُقنّع
    public function toEmployeeCard(): array
    {
        return [
            'no' => $this->number,
            'client' => self::maskClient($this->user?->name ?? ''),
            'clientId' => $this->user_id,
            'type' => $this->type,
            'subject' => $this->subject,
            'priority' => $this->priority ?: 'متوسطة',
            'dept' => $this->department,
            'lawyer' => $this->assigned_lawyer ?: '—',
            'lawyerId' => $this->assigned_lawyer_id,
            'status' => $this->status,
            'tone' => $this->tone,
        ];
    }

    // إخفاء اسم العميل للموظف — يطابق maskClient في الواجهة
    public static function maskClient(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '—') {
            return $name ?: '—';
        }
        $parts = preg_split('/\s+/', $name);
        $f = $parts[0] ?? '';
        $masked = mb_substr($f, 0, 1).'••••'.mb_substr($f, -1);
        $second = isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1).'•••' : '';

        return $masked.$second.' (مشفّر)';
    }
}
