<?php

namespace App\Models;

use App\Domain\Journey\Enums\ExecutionDocumentStatus;
use App\Models\Concerns\PurgesStoredFile;
use App\Support\ConversationFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مستند مطلوب من العميل على طلب تنفيذ (يطلبه قسم التنفيذ، يرفعه العميل) — نظير exDocPanel في التصميم.
 */
class ExecutionDocument extends Model
{
    use PurgesStoredFile;

    protected $fillable = ['execution_id', 'label', 'status', 'uploaded_by', 'path', 'mime', 'size', 'uploaded_at', 'doc_type', 'summary'];

    protected $casts = [
        'size' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    /** هل رفعه العميل بنفسه؟ — `uploaded_by` افتراضه `client` (كلّ مسارات الرفع له)، و`staff` لما نُسخ من مرفقات المكتب. */
    public function isFromClient(): bool
    {
        return $this->uploaded_by !== 'staff';
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    // الشكل الذي تتوقعه الواجهة (يطابق exDocPanel: label/status/tone/fileName/canUpload)
    public function toData(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status,
            'tone' => $this->statusEnum()?->tone() ?? 'b-amber',
            'fileName' => $this->path ? basename((string) $this->path) : null,
            // الموظّف بلا «تنزيل مرفقات الملفات» يرى الاسم بلا رابطٍ يردّه الخادم (`downloadDocument`)
            'canDownload' => $this->path !== null && self::viewerMayDownload(),
            'canUpload' => $this->acceptsUpload(),
            // حارس `ExecFlowController::reviewDocument` نفسه — الواجهة كانت تقارن «مرفوع» نصّاً
            'canReview' => $this->awaitsReview(),
            // وصل المكتبَ (رُفع أو قُبل) — لعدّاد «المستوفى» في لوحة المستندات
            'provided' => (bool) $this->statusEnum()?->provided(),
            'docType' => $this->doc_type ?? '',
            'summary' => $this->summary ?? '',
        ];
    }

    /** الشرط الإضافيّ على الموظّف وحده؛ بقيّة الأدوار كما يحرسها `ExecFlowController::downloadDocument`. */
    private static function viewerMayDownload(): bool
    {
        $viewer = auth()->user();

        return $viewer === null || ! $viewer->isEmployee() || ConversationFiles::employeeMayDownload($viewer);
    }

    public function statusEnum(): ?ExecutionDocumentStatus
    {
        return ExecutionDocumentStatus::tryFrom((string) $this->status);
    }

    /** يقبل رفع العميل؟ — حكم الشاشة (`canUpload`) وحارس `ExecFlowController::uploadDocument` معاً. */
    public function acceptsUpload(): bool
    {
        return (bool) $this->statusEnum()?->acceptsUpload();
    }

    /** بانتظار مراجعة المكتب؟ — حكم الشاشة (`canReview`) وحارس `ExecFlowController::reviewDocument` معاً. */
    public function awaitsReview(): bool
    {
        return (bool) $this->statusEnum()?->awaitsReview();
    }
}
