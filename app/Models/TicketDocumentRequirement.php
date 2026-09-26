<?php

namespace App\Models;

use App\Enums\RequirementCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * مطابقةُ مستندٍ مرفق ببندٍ من قائمة مستندات القسم — يكتبها `TicketDocumentRequirements` وحده.
 *
 * @property int $id
 * @property int $ticket_document_id
 * @property int|null $legal_department_document_id
 * @property string $requirement
 * @property RequirementCheck $checked_by
 * @property int|null $checked_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TicketDocumentRequirement extends Model
{
    protected $fillable = ['ticket_document_id', 'legal_department_document_id', 'requirement', 'checked_by', 'checked_by_user_id'];

    protected $casts = [
        'ticket_document_id' => 'integer',
        'legal_department_document_id' => 'integer',
        'checked_by' => RequirementCheck::class,
        'checked_by_user_id' => 'integer',
    ];

    /** @return BelongsTo<TicketDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(TicketDocument::class, 'ticket_document_id');
    }
}
