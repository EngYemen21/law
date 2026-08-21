<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseDocument extends Model
{
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

    /** الشكل الذي تتوقعه الواجهة (بطاقة مستندات القضية). */
    public function toData(): array
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
        ];
    }
}
