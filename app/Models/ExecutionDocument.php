<?php

namespace App\Models;

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

    protected $fillable = ['execution_id', 'label', 'status', 'path', 'mime', 'size', 'uploaded_at', 'doc_type', 'summary'];

    protected $casts = [
        'size' => 'integer',
        'uploaded_at' => 'datetime',
    ];

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
            'tone' => self::statusTone($this->status),
            'fileName' => $this->path ? basename((string) $this->path) : null,
            // الموظّف بلا «تنزيل مرفقات الملفات» يرى الاسم بلا رابطٍ يردّه الخادم (`downloadDocument`)
            'canDownload' => $this->path !== null && self::viewerMayDownload(),
            'canUpload' => in_array($this->status, ['مطلوب', 'مرفوض'], true),
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

    // نغمة حالة المستند (يطابق exDocStatusTone)
    private static function statusTone(string $status): string
    {
        return match ($status) {
            'مقبول' => 'b-green',
            'مرفوع' => 'b-blue',
            'مرفوض' => 'b-red',
            default => 'b-amber',
        };
    }
}
