<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * بندٌ في قائمة المستندات المطلوبة لقسمٍ قانونيّ (قرار المالك 2026-09-26).
 *
 * القراءة عبر `LegalCatalogue::documentsFor` (ذاكرة لكلّ طلب)، والكتابة عبر `LegalCatalogueEditor`
 * وحده — كبقيّة الكتالوج.
 *
 * @property int $id
 * @property int $legal_department_id
 * @property string $name
 * @property bool $required
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LegalDepartmentDocument extends Model
{
    protected $fillable = ['legal_department_id', 'name', 'required', 'sort_order'];

    protected $casts = [
        'legal_department_id' => 'integer',
        'required' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<LegalDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(LegalDepartment::class, 'legal_department_id');
    }
}
