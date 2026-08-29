<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مصدر قانونيّ معتمد — نظام/مادة/لائحة يُستشهد بها في المخرجات.
 *
 * **لا يُملأ هذا الجدول برمجياً ولا بمعرفة نموذج.** المحتوى يأتي من الفريق القانونيّ
 * مع بياناته الحاكمة: من يملك المصدر، وتاريخ سريانه، وإصداره، وولايته، ونطاق
 * استعماله، ومتى راجعه محامٍ. مادّةٌ بلا هذه البيانات ليست مصدراً — هي نصّ مجهول.
 *
 * الغرض التقنيّ: أن يصير الاستشهاد **قابلاً للتحقّق خادمياً**. النموذج لا يُصدَّق في
 * رقم مادّة؛ يُطابَق `source_id` بصفٍّ حقيقيّ هنا وإلّا سقط الادّعاء إلى غير المدعوم.
 */
class LegalSource extends Model
{
    /** معتمد للاستشهاد. */
    public const STATUS_APPROVED = 'معتمد';

    /** مُدخَل بانتظار المراجعة القانونيّة — لا يُستشهد به. */
    public const STATUS_DRAFT = 'مسودة';

    /** موقوف (نُسخ أو عُدّل النظام) — لا يُستشهد به. */
    public const STATUS_SUSPENDED = 'موقوف';

    protected $fillable = [
        'ref', 'system_name', 'article_no', 'title', 'text',
        'jurisdiction', 'domain', 'version',
        'effective_from', 'effective_to',
        'source_owner', 'source_url',
        'legal_review_at', 'reviewed_by',
        'usage_scope', 'status',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'legal_review_at' => 'date',
    ];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** معتمد فقط — المسودّة والموقوف لا يُستشهد بهما مهما طابقا الموضوع. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /** نافذ في التاريخ المرجعيّ: بدأ سريانه ولم ينتهِ. */
    public function scopeEffectiveAt(Builder $query, \DateTimeInterface $asOf): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $asOf))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf));
    }

    /** الاستشهاد المعروض للمراجع: نظام + مادة + إصدار. */
    public function citation(): string
    {
        return trim(implode(' · ', array_filter([
            $this->system_name,
            $this->article_no ? "المادة {$this->article_no}" : null,
            $this->version ? "إصدار {$this->version}" : null,
        ])));
    }
}
