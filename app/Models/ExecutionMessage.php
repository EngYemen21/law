<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionMessage extends Model
{
    protected $fillable = ['execution_id', 'who', 'name', 'role', 'body', 'time_label'];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    // الشكل الذي تتوقعه الواجهة (يطابق Message في chat.ts)
    public function toMessage(): array
    {
        return [
            'who' => $this->who,
            'name' => $this->name,
            'role' => $this->role,
            'text' => $this->body,
            'time' => $this->time_label,
        ];
    }
}
