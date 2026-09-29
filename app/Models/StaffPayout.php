<?php

namespace App\Models;

use App\Enums\PayoutKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * قيد صرفٍ للموظّف — يُسجَّل ويُلغى، ولا يُعدَّل ولا يُحذف (`Admin\StaffPayoutController`).
 *
 * @property int $id
 * @property string|null $voucher_no
 * @property int $user_id
 * @property PayoutKind $kind
 * @property int $amount
 * @property string $period
 * @property int|null $case_id
 * @property int|null $execution_id
 * @property string|null $note
 * @property Carbon $paid_at
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 */
class StaffPayout extends Model
{
    protected $fillable = [
        'voucher_no', 'user_id', 'kind', 'amount', 'period', 'case_id', 'execution_id', 'note', 'paid_at', 'recorded_by',
        'voided_at', 'void_reason', 'voided_by',
    ];

    protected $casts = [
        'kind' => PayoutKind::class,
        'amount' => 'integer',
        'paid_at' => 'date',
        'voided_at' => 'datetime',
    ];

    /** القيود السارية — الملغى يبقى في السجلّ ولا يُطرح من المستحقّ. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
