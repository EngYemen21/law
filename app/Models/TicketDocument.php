<?php

namespace App\Models;

use App\Models\Concerns\PurgesStoredFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketDocument extends Model
{
    use PurgesStoredFile;

    protected $fillable = [
        'ticket_id', 'name', 'path', 'mime', 'size', 'status', 'doc_type', 'summary', 'reason', 'summary_approved',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
