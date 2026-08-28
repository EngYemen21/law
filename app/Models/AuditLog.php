<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_name',
        'user_role',
        'action',
        'category',
        'auditable_type',
        'auditable_id',
        'auditable_ref',
        'description',
        'before_state',
        'after_state',
        'ip_address',
        'user_agent',
        'severity',
        // وقت الحدث يُمرَّر صراحةً (الكتابة عبر الطابور والاستيراد التاريخي) —
        // بدونهما في fillable كان create() يتجاهلهما صامتاً ويؤرّخ بوقت الإدراج
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * تجهيز حمولة قيد التدقيق — يلتقط سياق طلب HTTP (المستخدم/IP/المتصفح) لحظة الحدث،
     * فتصلح الحمولة للكتابة الفورية أو عبر الطابور (العامل بلا سياق طلب).
     *
     * @return array<string, mixed>
     */
    public static function preparePayload(
        string $action,
        string $description,
        string $category = 'عام',
        string $severity = 'info',
        ?Model $auditable = null,
        ?string $auditableRef = null,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?User $user = null,
        ?Request $request = null
    ): array {
        $req = $request ?? (request() instanceof Request ? request() : null);
        $currentUser = $user ?? ($req ? $req->user() : null);

        $userName = $currentUser ? $currentUser->name : 'النظام';
        // الدور Enum مصبوب — نخزّن قيمته النصية؛ وبلا طلب HTTP لا نختلق IP/متصفحًا
        $userRole = $currentUser ? ($currentUser->role?->value ?? 'user') : 'system';

        $ref = $auditableRef;
        if (! $ref && $auditable) {
            $ref = $auditable->ref ?? $auditable->reference_no ?? ('#'.$auditable->getKey());
        }

        return [
            'user_id' => $currentUser?->id,
            'user_name' => $userName,
            'user_role' => $userRole,
            'action' => $action,
            'category' => $category,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->getKey(),
            'auditable_ref' => $ref,
            'description' => $description,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'ip_address' => $req?->ip(),
            'user_agent' => $req?->userAgent(),
            'severity' => in_array($severity, ['info', 'warning', 'critical']) ? $severity : 'info',
        ];
    }

    /**
     * تسجيل قيد تدقيق أمني جديد فورياً (كتابة متزامنة — تستعملها الاختبارات والاستيراد؛
     * مسارات التطبيق تمرّ عبر Audit::log الذي يكتب من الطابور)
     */
    public static function record(
        string $action,
        string $description,
        string $category = 'عام',
        string $severity = 'info',
        ?Model $auditable = null,
        ?string $auditableRef = null,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?User $user = null,
        ?Request $request = null
    ): self {
        return self::create(self::preparePayload(
            $action, $description, $category, $severity,
            $auditable, $auditableRef, $beforeState, $afterState, $user, $request,
        ));
    }

    /**
     * تحويل السجل لبطاقة واجهة المستخدم 360°
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'userName' => $this->user_name,
            'userRole' => $this->user_role,
            'userAvatar' => $this->user?->avatar ?? null,
            'action' => $this->action,
            'category' => $this->category,
            'auditableRef' => $this->auditable_ref ?? '—',
            'auditableType' => $this->auditable_type ? class_basename($this->auditable_type) : null,
            'description' => $this->description,
            'beforeState' => $this->before_state,
            'afterState' => $this->after_state,
            'ipAddress' => $this->ip_address ?? '—',
            'userAgent' => $this->user_agent ?? '—',
            'severity' => $this->severity,
            'time' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : '—',
            'timeHuman' => $this->created_at ? $this->created_at->diffForHumans() : '—',
        ];
    }
}
