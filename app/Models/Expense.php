<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **مصروفٌ من مصروفات المكتب** — يُسجَّل ويُعتمد أو يُرفض ويُلغى بسببٍ، ولا يُعدَّل ولا يُحذف
 * (`Finance\Expenses`). المعتمد وحده يُحسب ويحمل سند صرف (`voucher_no`).
 *
 * @property int $id
 * @property string|null $voucher_no
 * @property Carbon $spent_on
 * @property ExpenseCategory $category
 * @property string $description
 * @property int $amount_halalas
 * @property int $vat_halalas
 * @property string|null $vendor
 * @property string $paid_from
 * @property string|null $reference
 * @property string|null $document_path
 * @property ExpenseStatus $status
 * @property int $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $reject_reason
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 */
class Expense extends Model
{
    /** مصدر الدفع ← تسميته على السند. */
    public const PAID_FROM = [
        'cash' => 'الصندوق (نقداً)',
        'bank' => 'الحساب البنكيّ',
    ];

    protected $fillable = [
        'voucher_no', 'spent_on', 'category', 'description', 'amount_halalas', 'vat_halalas', 'vendor',
        'paid_from', 'reference', 'document_path', 'status', 'created_by', 'approved_by', 'approved_at',
        'reject_reason', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'spent_on' => 'date',
        'category' => ExpenseCategory::class,
        'status' => ExpenseStatus::class,
        'amount_halalas' => 'integer',
        'vat_halalas' => 'integer',
        'approved_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * ما يُحسب في التقارير والأرباح والخسائر — المعتمد وحده (لا المنتظر ولا المرفوض ولا الملغى).
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', ExpenseStatus::Approved->value);
    }

    public function isPending(): bool
    {
        return $this->status === ExpenseStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === ExpenseStatus::Approved;
    }

    /** @return list<array{value:string,label:string}> — بشكل خيارات التعدادين (`ExpenseCategory::options`) */
    public static function paidFromOptions(): array
    {
        return array_map(fn (string $k, string $label) => ['value' => $k, 'label' => $label], array_keys(self::PAID_FROM), self::PAID_FROM);
    }

    public function paidFromLabel(): string
    {
        return self::PAID_FROM[$this->paid_from] ?? $this->paid_from;
    }
}
