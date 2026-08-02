<?php

namespace App\Models;

use App\Models\Concerns\HasBranch;
use App\Support\CorrFlow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Execution extends Model
{
    use HasBranch;

    protected $fillable = [
        'user_id', 'case_id', 'number', 'subject', 'assigned_lawyer', 'assigned_lawyer_id', 'branch', 'court', 'status', 'tone', 'last_action',
        // تدفّق التنفيذ التجاريّ (10 مراحل)
        'stage', 'sanad', 'defendant', 'amount', 'notes', 'docs', 'client_code',
        'ai_done', 'ai_summary', 'ai_missing', 'ai_procedures',
        'decision', 'fee', 'vat', 'duration', 'pay_method', 'fee_approved', 'offer_status',
        'invoice_no', 'paid', 'paid_at', 'exec_no',
    ];

    protected $casts = [
        'stage' => 'integer',
        'amount' => 'integer',
        'fee' => 'integer',
        'vat' => 'integer',
        'docs' => 'array',
        'ai_done' => 'boolean',
        'ai_missing' => 'array',
        'ai_procedures' => 'array',
        'fee_approved' => 'boolean',
        'paid' => 'boolean',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    // حساب محامي التنفيذ المسند (المصدر الموثوق؛ العمود النصي للعرض فقط)
    public function assignedLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_lawyer_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ExecutionMessage::class)->orderBy('id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(ExecutionProcedure::class)->orderBy('id');
    }

    // مستندات مطلوبة من العميل على الطلب (يطلبها القسم، يرفعها العميل)
    public function documents(): HasMany
    {
        return $this->hasMany(ExecutionDocument::class)->orderBy('id');
    }

    // المخاطبات الرسميّة المرتبطة بملفّ التنفيذ
    public function correspondences(): HasMany
    {
        return $this->hasMany(Correspondence::class)->latest('id');
    }

    // فواتير أتعاب التنفيذ (المفتاح exec_id) — لدفع العرض عبر ميسّر
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'exec_id');
    }

    // ربط المسار برقم الطلب بدل المعرّف
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.execs)
    public function toCard(): array
    {
        return [
            'no' => $this->number,
            'subject' => $this->subject,
            'status' => $this->status,
            'tone' => $this->tone,
            'last' => $this->last_action,
            'lawyer' => $this->assigned_lawyer,
            'court' => $this->court,
        ];
    }

    /**
     * شكل سجلّ تدفّق التنفيذ التجاريّ للواجهة (يطابق نوع ExecReq في exec-flow.ts).
     * $masked: إخفاء اسم العميل لغير مالكه (المحامي/الموظف/الإدارة).
     */
    public function toFlowCard(bool $masked = false): array
    {
        $client = $this->user?->name ?? '—';
        $stage = $this->displayStage();

        return [
            'id' => $this->number,
            'client' => $masked ? self::maskName($client) : $client,
            'code' => $this->client_code ?? '',
            'sanad' => $this->sanad ?? '',
            'subject' => $this->subject,
            'defendant' => $this->defendant ?? '',
            'amount' => (int) $this->amount,
            'notes' => $this->notes ?? '',
            'docs' => $this->docs ?? [],
            'stage' => $stage,
            'channel' => 'exec.'.$this->id,
            'messages' => $this->relationLoaded('messages')
                ? $this->messages->where('who', '!=', 'note')->values()->map->toMessage()->all()
                : [],
            'docItems' => $this->relationLoaded('documents')
                ? $this->documents->map->toData()->all()
                : [],
            'lawyer' => $this->assigned_lawyer ?? '',
            'aiDone' => (bool) $this->ai_done,
            'aiSummary' => $this->ai_summary ?? '',
            'aiMissing' => $this->ai_missing ?? [],
            'aiProcedures' => $this->ai_procedures ?? [],
            'decision' => $this->decision ?? '',
            'fee' => (int) $this->fee,
            'vat' => (int) $this->vat,
            'duration' => $this->duration ?? '',
            'payMethod' => $this->pay_method ?? '',
            'feeApproved' => (bool) $this->fee_approved,
            'offerStatus' => $this->offer_status ?? '',
            'invoiceNo' => $this->invoice_no ?? '',
            'paid' => (bool) $this->paid,
            'execNo' => $this->exec_no ?? '',
            'procedures' => $this->procedures->map(fn (ExecutionProcedure $p) => [
                'a' => $p->title,
                't' => $p->created_at?->format('Y/m/d h:i') ?? '',
            ])->values()->all(),
            'closed' => $stage >= 9,
            'linkedCorr' => $this->relationLoaded('correspondences')
                ? $this->correspondences->map(fn (Correspondence $c) => [
                    'id' => $c->number,
                    'entity' => $c->entity,
                    'stageLabel' => CorrFlow::label((int) $c->stage).($c->reply_body ? ' · ورد الرد' : ''),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * مرحلة العرض: التنفيذات القديمة (stage=null، من البذور/تحويل قضية→تنفيذ) تُعرَض للعميل
     * كملفّات مفتوحة أصلاً — «مغلق»(9) إن اكتملت، وإلا «قيد التنفيذ»(8). العمود يبقى null في القاعدة
     * كي لا تتأثّر استعلامات بقيّة الأدوار (whereNull/whereNotNull).
     */
    private function displayStage(): int
    {
        if ($this->stage !== null) {
            return (int) $this->stage;
        }

        // «مغلق» فقط = مؤرشف (9)؛ «مكتمل» وغيرها = قيد التنفيذ (8) فتبقى قابلة للإغلاق من الإدارة
        return $this->status === 'مغلق' ? 9 : 8;
    }

    private static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) <= 1) {
            return $name;
        }

        return $parts[0].' '.implode(' ', array_map(fn ($p) => mb_substr($p, 0, 1).'…', array_slice($parts, 1)));
    }
}
