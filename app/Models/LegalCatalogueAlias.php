<?php

namespace App\Models;

use App\Support\LegalCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * اسمٌ قديم أو مرادف يُطابَق مع قسمٍ (وخدمةٍ إن حُدّدت).
 *
 * لماذا: التذاكر والقضايا القديمة، ومخرجات الذكاء، ومصادر المعرفة تحمل صياغاتٍ متفرّقة
 * («القسم التجاري» · «التجاري» · «القضايا التجارية»). الاسم البديل يردّها كلَّها إلى قسمٍ واحد
 * بدل المطابقة الاحتوائيّة التي كانت تُخطئ («المنافسة والامتثال التجاري» ← «القضايا التجارية»).
 *
 * `alias_folded` يُحسب دائماً عبر `LegalCatalogue::foldName` عند الحفظ — لا يُكتب يدويّاً.
 *
 * @property int $id
 * @property string $alias
 * @property string $alias_folded
 * @property int $legal_department_id
 * @property int|null $legal_service_id
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LegalCatalogueAlias extends Model
{
    public const SOURCE_SEED = 'seed';

    public const SOURCE_RENAME = 'rename';

    protected $fillable = ['alias', 'legal_department_id', 'legal_service_id', 'source'];

    protected $casts = [
        'legal_department_id' => 'integer',
        'legal_service_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $alias) {
            $alias->alias_folded = LegalCatalogue::foldName($alias->alias);
        });
    }

    /** @return BelongsTo<LegalDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(LegalDepartment::class, 'legal_department_id');
    }

    /** @return BelongsTo<LegalService, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(LegalService::class, 'legal_service_id');
    }
}
