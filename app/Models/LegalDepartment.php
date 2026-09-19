<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * قسمٌ قانونيّ في الكتالوج — ما يختاره العميل عند فتح التذكرة، ويُسنَد به المحامي المختصّ.
 *
 * لا يُحذف: يُوقَف (`status = suspended`) فيختفي من الاختيار ويبقى لما يشير إليه من تذاكر وقضايا.
 * القراءة المتكرّرة تمرّ عبر `App\Support\LegalCatalogue` (ذاكرة لكلّ طلب) لا بالاستعلام المباشر.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 * @property string $status
 * @property bool $requires_specific_authority
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LegalDepartment extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['code', 'name', 'sort_order', 'status', 'requires_specific_authority'];

    protected $casts = [
        'sort_order' => 'integer',
        'requires_specific_authority' => 'boolean',
    ];

    /** @return HasMany<LegalService, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(LegalService::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<LegalCatalogueAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(LegalCatalogueAlias::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @param  Builder<LegalDepartment>  $query
     * @return Builder<LegalDepartment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @param  Builder<LegalDepartment>  $query
     * @return Builder<LegalDepartment>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
