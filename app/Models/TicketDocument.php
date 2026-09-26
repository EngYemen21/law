<?php

namespace App\Models;

use App\Models\Concerns\PurgesStoredFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketDocument extends Model
{
    use PurgesStoredFile;

    protected $fillable = [
        'ticket_id', 'name', 'path', 'mime', 'size', 'status', 'doc_type', 'summary', 'reason', 'summary_approved',
        'requirements_checked_at',
    ];

    protected $casts = [
        // متى فُحص الملفّ مقابل قائمة مستندات القسم — فارغٌ ⇒ «لم يُتحقّق» (TicketDocumentRequirements)
        'requirements_checked_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * بنود قائمة القسم التي يستوفيها هذا المستند.
     *
     * @return HasMany<TicketDocumentRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(TicketDocumentRequirement::class);
    }
}
