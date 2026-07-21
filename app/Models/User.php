<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 * @property string|null $branch
 * @property string|null $department
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
    'name', 'email', 'password', 'role', 'avatar_initials', 'title', 'phone',
    'status', 'branch', 'department', 'distribution_mode', 'job_title',
    'pay_type', 'salary', 'pay_pct', 'session_fee',
    'national_id', 'join_date', 'work_start', 'work_end',
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
            'password' => 'hashed',
            'role' => Role::class,
            'join_date' => 'date',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    // الحساب مفعّل؟ (الموقوف يُمنع من الدخول)
    public function isActive(): bool
    {
        return $this->status !== 'suspended';
    }

    // وصف الأجر (يطابق payLabel في staff.tsx)
    public function payLabel(): string
    {
        return match ($this->pay_type) {
            'salary' => 'راتب ثابت: '.number_format($this->salary).' ر.س/شهري',
            'pct' => 'نسبة: '.rtrim(rtrim((string) $this->pay_pct, '0'), '.').'%',
            'both' => 'راتب '.number_format($this->salary).' ر.س + نسبة '.rtrim(rtrim((string) $this->pay_pct, '0'), '.').'%',
            'session' => 'بالجلسة: '.number_format((int) $this->session_fee).' ر.س/جلسة',
            default => '—',
        };
    }

    // بطاقة الموظف للواجهة (تطابق واجهة Staff في employee-data)
    public function staffCard(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->job_title ?? $this->role->label(),
            'branch' => $this->branch ?? '—',
            'dept' => $this->department ?? '—',
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
}
