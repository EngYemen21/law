<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMessage extends Model
{
    protected $fillable = ['ticket_id', 'who', 'name', 'role', 'body', 'time_label'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    // الشكل الذي تتوقعه الواجهة (يطابق Message في chat.ts)
    public function toMessage(): array
    {
        return [
            'id' => $this->id,
            'who' => $this->who,
            'name' => $this->name,
            'role' => $this->role,
            'text' => $this->body,
            'time' => $this->time_label,
        ];
    }
}
