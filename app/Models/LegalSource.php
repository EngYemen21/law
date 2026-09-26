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

    /**
     * **الحالات بترتيب عرضها ونبرتها — المصدر الوحيد لخيارات الحالة ولونها.**
     * كانت الشاشة تقارن `status === 'معتمد'` وتحمل قاموس ألوانٍ بمفاتيح عربيّة، فتغييرُ نصّ
     * حالةٍ هنا يُبقي الزرّ الخطأ ظاهراً هناك بصمت. الآن الشاشة تقرأ ما يُحسب هنا ولا تقارن.
     */
    public const STATUSES = [
        self::STATUS_DRAFT => 'b-amber',
        self::STATUS_APPROVED => 'b-green',
        self::STATUS_SUSPENDED => 'b-grey',
    ];

    /** @return list<array{value:string,label:string}> خيارات مرشِّح الحالة */
    public static function statusOptions(): array
    {
        return array_map(fn (string $s) => ['value' => $s, 'label' => $s], array_keys(self::STATUSES));
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status] ?? 'b-grey';
    }

    /** الاعتماد لما لم يُعتمد بعد (مسودّة أو موقوف يُعاد). */
    public function canApprove(): bool
    {
        return $this->status !== self::STATUS_APPROVED;
    }

    /** الإيقاف للمعتمد وحده — المسودّة لا يُستشهد بها أصلاً فلا معنى لإيقافها. */
    public function canSuspend(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

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
