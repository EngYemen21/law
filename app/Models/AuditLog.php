<?php

namespace App\Models;

use App\Enums\Role;
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

    /**
     * **مظهر كلّ فئة (لونها وأيقونتها) — في موضعٍ واحد بجوار القيد لا في الشاشة.**
     *
     * الفئة نصٌّ عربيّ مخزَّن يكتبه `Audit::log`، وكانت الشاشة تقارنه بـ`switch` لتلوّنه —
     * مقارنةٌ بالنصّ العربيّ في الواجهة ونسختان من القائمة تتباعدان (فئة «تذاكر» بلا لون،
     * و«المساعد القانوني» بلا أيقونة). الآن الشاشة تقرأ `categoryColor/categoryIcon` من البطاقة.
     *
     * @var array<string, array{0:string, 1:string}> الفئة ⇦ [اللون، الأيقونة]
     */
    public const CATEGORY_STYLES = [
        'أمن وحماية' => ['#dc2626', 'clock'],
        'مالية وفواتير' => ['#C0832B', 'card'],
        'استشارات' => ['#0E5C9C', 'scale'],
        'اجتماعات' => ['#11A0C8', 'video'],
        'قضايا وتنفيذ' => ['#1E9D6B', 'folder'],
        'قضايا' => ['#1E9D6B', 'folder'],
        'تنفيذ' => ['#1E9D6B', 'folder'],
        'تذاكر' => ['#0E5C9C', 'folder'],
        'تذاكر وتنفيذ' => ['#0E5C9C', 'folder'],
        'المساعد القانوني' => ['#6c5ce7', 'sparkles'],
        'الإدارة العليا' => ['#13314F', 'user'],
        'موظفون وصلاحيات' => ['#13314F', 'user'],
    ];

    /** الفئة التي لا مظهر لها أعلاه — تُعرض محايدةً لا تُسقط الشاشة. */
    private const DEFAULT_STYLE = ['#4B5563', 'doc'];

    /** @var array<string,string> درجة الأهمية ⇦ اسمها كما يقرؤه المدير (القيم الثلاث في `preparePayload`) */
    public const SEVERITY_LABELS = [
        'info' => 'عادي',
        'warning' => 'تحذيري',
        'critical' => 'حرج',
    ];

    /** @return list<array{value:string,label:string}> خيارات مرشّح الأهمية */
    public static function severityOptions(): array
    {
        return array_map(fn (string $v, string $l) => ['value' => $v, 'label' => $l], array_keys(self::SEVERITY_LABELS), self::SEVERITY_LABELS);
    }

    /**
     * اسم دور الفاعل بالعربيّة من `Role` نفسه — و«النظام الآليّ» لما كتبه النظام. كانت الشاشة
     * تحمل أسماءً خاصّة بها («مستشار قانوني»، «موظف استقبال») تخالف بقيّة المنظومة.
     */
    public static function roleLabelOf(?string $role): string
    {
        return Role::tryFrom((string) $role)?->label() ?? 'النظام الآليّ';
    }

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
            'severityLabel' => self::SEVERITY_LABELS[$this->severity] ?? self::SEVERITY_LABELS['info'],
            'roleLabel' => self::roleLabelOf($this->user_role),
            'roleTone' => match (Role::tryFrom((string) $this->user_role)) {
                Role::Admin => 'b-blue',
                Role::Lawyer => 'b-green',
                Role::Employee => 'b-amber',
                default => 'b-grey',
            },
            'categoryColor' => (self::CATEGORY_STYLES[$this->category] ?? self::DEFAULT_STYLE)[0],
            'categoryIcon' => (self::CATEGORY_STYLES[$this->category] ?? self::DEFAULT_STYLE)[1],
            'time' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : '—',
            'timeHuman' => $this->created_at ? $this->created_at->diffForHumans() : '—',
        ];
    }
}
