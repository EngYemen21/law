<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * خدمةٌ قانونيّة داخل قسم — ما يُحفظ في `tickets.type` حين يختاره العميل.
 *
 * الاسم فريدٌ داخل قسمه فقط؛ لا يُحذف بل يُوقَف كقسمه.
 *
 * @property int $id
 * @property int $legal_department_id
 * @property string $name
 * @property int $sort_order
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LegalService extends Model
{
    protected $fillable = ['legal_department_id', 'name', 'sort_order', 'status'];

    protected $casts = [
        'legal_department_id' => 'integer',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<LegalDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(LegalDepartment::class, 'legal_department_id');
    }

    public function isActive(): bool
    {
        return $this->status === LegalDepartment::STATUS_ACTIVE;
    }

    /**
     * @param  Builder<LegalService>  $query
     * @return Builder<LegalService>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', LegalDepartment::STATUS_ACTIVE);
    }
}
