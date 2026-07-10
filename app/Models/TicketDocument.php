<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketDocument extends Model
{
    protected $fillable = [
        'ticket_id', 'name', 'path', 'mime', 'size', 'status', 'doc_type', 'summary', 'reason',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
