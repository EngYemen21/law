<?php

namespace App\Models;

use App\Support\RichHtml;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * محرر الصياغة القانونية — مستندات WYSIWYG منسّقة.
 *
 * جدول legal_documents مستقل عن documents (مرفقات العملاء).
 * يحتوي على محتوى HTML للعرض + JSON لإعادة التحرير عبر TipTap.
 */
class LegalDocument extends Model
{
    use SoftDeletes;

    protected $table = 'legal_documents';

    protected $fillable = [
        'title',
        'type',
        'user_id',
        'ticket_id',
        'case_id',
        'content_html',
        'content_json',
        'status',
        'metadata',
        'header_config',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'content_json' => 'array',
        'metadata' => 'array',
        'header_config' => 'array',
        'approved_at' => 'datetime',
    ];

    /**
     * المحتوى يُنقّى بقائمة سماح عند الكتابة **وعند القراءة** — القراءة تحرس ما حُفظ قبل التنقية،
     * فلا يصل سكربتٌ قديم صفحةَ الطباعة ولا كرومَ الـPDF. راجع `RichHtml`.
     */
    protected function contentHtml(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : RichHtml::clean($value),
            set: fn (?string $value) => $value === null ? null : RichHtml::clean($value),
        );
    }

    // ── العلاقات ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    // ── الأنواع المدعومة ──

    public const TYPES = [
        'lawsuit' => 'لائحة / صحيفة دعوى',
        'memo' => 'مذكرة قضائية',
        'summary' => 'ملخص / رأي قانوني',
        'contract' => 'عقد',
        'letter' => 'خطاب رسمي',
        'free' => 'مستند حر',
    ];

    // ── الحالات ──

    public const STATUSES = [
        'draft' => 'مسودة',
        'review' => 'قيد المراجعة',
        'approved' => 'معتمد',
        'archived' => 'مؤرشف',
    ];

    // ── البيانات للبطاقة (القائمة) ──

    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'typeLabel' => self::TYPES[$this->type] ?? $this->type,
            'status' => $this->status,
            'statusLabel' => self::STATUSES[$this->status] ?? $this->status,
            'author' => $this->user?->name,
            'ticketNo' => $this->ticket?->number,
            'caseNo' => $this->legalCase?->number,
            'updatedAt' => $this->updated_at?->translatedFormat('d M Y · h:i A'),
            'createdAt' => $this->created_at?->translatedFormat('d M Y'),
            'approved' => $this->status === 'approved',
            'approvedBy' => $this->approver?->name,
            'approvedAt' => $this->approved_at?->translatedFormat('d M Y · h:i A'),
        ];
    }

    // ── البيانات الكاملة للمحرر ──

    public function toEditorData(): array
    {
        return [
            ...$this->toCard(),
            'case' => $this->legalCase ? ['id' => $this->case_id, 'no' => $this->legalCase->number] : null,
            'contentHtml' => $this->content_html,
            'contentJson' => $this->content_json,
            'metadata' => $this->metadata,
            'headerConfig' => $this->header_config ?? self::defaultHeader(),
        ];
    }

    // ── ترويسة افتراضية ──

    public static function defaultHeader(): array
    {
        return [
            'showHeader' => true,
            'officeName' => 'مكتب المحاماة',
            'officeNameEn' => 'Law Office',
            'logoUrl' => '/images/021.png',
            'address' => '',
            'phone' => '',
            'email' => '',
            'licenseNo' => '',
        ];
    }
}
