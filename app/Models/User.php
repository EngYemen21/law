<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\PayType;
use App\Enums\Role;
use App\Support\LawyerSpecialties;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Role $role
 * @property string|null $avatar_initials
 * @property string|null $title
 * @property string|null $phone
 * @property string $status
 * @property string|null $department
 * @property bool $covers_all_departments
 * @property string $distribution_mode // auto | manual
 * @property string|null $job_title
 * @property string|null $pay_type
 * @property int $salary
 * @property float|null $pay_pct
 * @property int|null $session_fee
 * @property string|null $national_id
 * @property Carbon|null $join_date
 * @property string|null $work_start
 * @property string|null $work_end
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'email', 'password', 'role', 'avatar_initials', 'title', 'phone', 'phone_verified_at', 'email_verified_at',
    'status', 'department', 'distribution_mode', 'job_title',
    'pay_type', 'salary', 'pay_pct', 'session_fee',
    'national_id', 'join_date', 'work_start', 'work_end',
    'covers_all_departments',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'join_date' => 'date',
            'covers_all_departments' => 'boolean',
        ];
    }

    /**
     * أقسام المحامي في الكتالوج — له أكثر من تخصّص (قرار المالك 2026-09-14).
     * المطابقة تمرّ عبر `App\Support\LawyerSpecialties` لا بالقراءة المباشرة.
     *
     * @return BelongsToMany<LegalDepartment, $this>
     */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(LegalDepartment::class, 'lawyer_specialties')->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isEmployee(): bool
    {
        return $this->role === Role::Employee;
    }

    public function isLawyer(): bool
    {
        return $this->role === Role::Lawyer;
    }

    public function isClient(): bool
    {
        return $this->role === Role::Client;
    }

    // الحساب مفعّل؟ (الموقوف يُمنع من الدخول)
    public function isActive(): bool
    {
        return $this->status !== 'suspended';
    }

    /**
     * **المحامون الذين يقبلهم `ActiveLawyer`** — قائمةُ الاختيار في النماذج تُبنى من هنا، فلا يُعرض
     * محامٍ موقوف ثمّ يُردّ اختياره بـ«غير نشط». (القاعدة نفسها: `isActive` = غير موقوف.)
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActiveLawyers($query)
    {
        return $query->where('role', Role::Lawyer)->where('status', '!=', 'suspended');
    }

    /** نوع الأجر مصنَّفاً — `null` لموظّفٍ لم يُضبط أجره. */
    public function payType(): ?PayType
    {
        return PayType::tryFrom((string) $this->pay_type);
    }

    // وصف الأجر (يطابق payLabel في staff.tsx)
    public function payLabel(): string
    {
        $pct = rtrim(rtrim((string) $this->pay_pct, '0'), '.');

        return match ($this->payType()) {
            PayType::Salary => 'راتب ثابت: '.number_format($this->salary).' ر.س/شهري',
            PayType::Percent => 'نسبة: '.$pct.'%',
            PayType::SalaryAndPercent => 'راتب '.number_format($this->salary).' ر.س + نسبة '.$pct.'%',
            PayType::Session => 'بالجلسة: '.number_format((int) $this->session_fee).' ر.س/جلسة',
            null => '—',
        };
    }

    // بطاقة الموظف للواجهة (تطابق واجهة Staff في employee-data)
    public function staffCard(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->job_title ?? $this->role->label(),
            // للمحامي: تخصّصاته في الكتالوج (قد تكون عدّة)؛ لغيره: قسمه الإداريّ
            'dept' => $this->isLawyer() ? LawyerSpecialties::label($this) : ($this->department ?? '—'),
            'specialtyIds' => $this->isLawyer() ? LawyerSpecialties::departmentIds($this) : [],
            'coversAll' => $this->isLawyer() && LawyerSpecialties::coversAll($this),
            'pay' => $this->payLabel(),
            'salary' => $this->salary,
            'status' => $this->isActive() ? 'نشط' : 'موقوف',
            'perms' => $this->getPermissionNames()->all(),
            'email' => $this->email,
            'mobile' => $this->phone ?? '—',
            'nid' => $this->national_id ?? '—',
            'join' => $this->join_date?->format('Y-m-d') ?? '—',
            'start' => $this->work_start ?? '—',
            'end' => $this->work_end ?? '—',
            // قيم خام لتعبئة نموذج التعديل
            'roleKey' => $this->role->value,
            'payType' => $this->pay_type,
            'pct' => $this->pay_pct !== null ? (float) $this->pay_pct : null,
            'sessionFee' => $this->session_fee,
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /** قضايا العميل (بمعرّف المستخدم) — للتحميل المسبق في دليل العملاء. */
    public function cases(): HasMany
    {
        return $this->hasMany(LegalCase::class);
    }

    /** استشارات العميل (بمعرّف المستخدم) — للتحميل المسبق في دليل العملاء. */
    public function consults(): HasMany
    {
        return $this->hasMany(Consult::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** التذاكر المُسنَدة لهذا المحامي (assigned_lawyer_id) — لعدّ الحمل بـwithCount. */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_lawyer_id');
    }

    /**
     * رمز أمان موثوق وثابت لتمكين مزامنة التقويم الحي (Live iCal Feed) دون كشف بيانات تسجيل الدخول.
     */
    public function calendarToken(): string
    {
        $seed = 'user-cal:'.$this->id.':'.($this->created_at?->timestamp ?? 1700000000);

        return substr(hash_hmac('sha256', $seed, (string) config('app.key')), 0, 32);
    }

    /**
     * رابط التغذية الحية لتقويم جوجل والأنظمة المتوافقة (Subscription URL).
     */
    public function calendarFeedUrl(): string
    {
        return url("/calendar/feed/{$this->id}/".$this->calendarToken().'.ics');
    }

    /**
     * رابط الاشتراك المباشر لتقويم الجوال (Webcal Protocol — iOS / Android / macOS).
     */
    public function calendarWebcalUrl(): string
    {
        $feedUrl = $this->calendarFeedUrl();

        return (string) preg_replace('/^https?:\/\//i', 'webcal://', $feedUrl);
    }
}
