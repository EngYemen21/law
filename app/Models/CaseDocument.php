<?php

namespace App\Models;

use App\Models\Concerns\PurgesStoredFile;
use App\Support\ConversationFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseDocument extends Model
{
    use PurgesStoredFile;

    protected $fillable = [
        'case_id', 'hearing_id', 'name', 'path', 'mime', 'size', 'uploaded_by', 'status', 'doc_type', 'summary',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    /** هل رفعه العميل بنفسه؟ — `uploaded_by` يكتبه الرافع (`client` · `lawyer` · `staff`). */
    public function isFromClient(): bool
    {
        return $this->uploaded_by === 'client';
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(CaseHearing::class, 'hearing_id');
    }

    /** رابط التنزيل لمن تُجيزه `ConversationFiles` (العميل صاحبه، المحامي المسنَد، الموظّف بصلاحيّته، الإدارة) — و`null` لغيرهم. */
    private function downloadUrlFor(?User $viewer): ?string
    {
        if ($viewer === null || $this->path === null) {
            return null;
        }

        return ConversationFiles::canDownload($viewer, $this)
            ? ConversationFiles::url('case', $this->id)
            : null;
    }

    /**
     * الشكل الذي تتوقعه الواجهة (بطاقة مستندات القضية).
     *
     * `$viewer` يحدّد إظهار رابط التنزيل — نمط Consult::toCard($viewer) المعتمد.
     * السياسة: `ConversationFiles::canDownload` — ونُقض حجبُ الموظّف بقرار المالك 2026-09-11.
     */
    public function toData(?User $viewer = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hearingId' => $this->hearing_id,
            'hearingTitle' => $this->hearing?->title,
            // 'staff' أضيف حين صار الموظف يرفع مستندات القضية — كان يُعرض «العميل»
            'by' => match ($this->uploaded_by) {
                'lawyer' => 'المحامي',
                'staff' => 'المكتب',
                default => 'العميل',
            },
            'status' => $this->status,
            'docType' => $this->doc_type ?? '',
            'summary' => $this->summary ?? '',
            'date' => $this->created_at?->locale('ar')->translatedFormat('d F Y') ?: '',
            'downloadUrl' => $this->downloadUrlFor($viewer),
        ];
    }
}
