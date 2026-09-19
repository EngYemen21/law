<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * قسمٌ إداريّ للموظّفين («خدمة العملاء» · «الإدارة المالية»).
 *
 * منفصلٌ عمداً عن `LegalDepartment` (قرار المالك 2026-09-14): كانت قائمة أقسام الطاقم تخلط
 * الأقسام الإداريّة بالتخصّصات القانونيّة، فيُحوَّل ملفٌّ قانونيّ إلى «خدمة العملاء».
 *
 * @property int $id
 * @property string $name
 * @property int $sort_order
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StaffDepartment extends Model
{
    protected $fillable = ['name', 'sort_order', 'status'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * @param  Builder<StaffDepartment>  $query
     * @return Builder<StaffDepartment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', LegalDepartment::STATUS_ACTIVE);
    }
}
