<?php

namespace App\Models;

use App\Models\Concerns\PurgesStoredFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseDocument extends Model
{
    use PurgesStoredFile;

    protected $fillable = [
        'case_id', 'name', 'path', 'mime', 'size', 'uploaded_by', 'status', 'doc_type', 'summary',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
    }

    /** رابط التنزيل للمحامي المسنَد أو الإدارة — و`null` لكل من سواهما (بمن فيهم الموظف). */
    private function downloadUrlFor(?User $viewer): ?string
    {
        if ($viewer === null || $this->path === null) {
            return null;
        }

        $assigned = $this->legalCase?->assigned_lawyer_id;
        $allowed = $viewer->isAdmin() || ($viewer->isLawyer() && $assigned === $viewer->id);

        return $allowed
            ? route('lawyer.documents.download', ['type' => 'case', 'id' => $this->id])
            : null;
    }

    /**
     * الشكل الذي تتوقعه الواجهة (بطاقة مستندات القضية).
     *
     * `$viewer` يحدّد إظهار رابط التنزيل — نمط Consult::toCard($viewer) المعتمد.
     * السياسة: المحامي المسنَد وحده (والإدارة إشرافاً)؛ الموظف لا يفتح ولا ينزّل.
     */
    public function toData(?User $viewer = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
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
