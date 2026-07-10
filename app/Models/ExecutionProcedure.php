<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إجراء تنفيذ — يجرّيه المحامي (حجز/تحصيل/إخطار) ويتابعه العميل.
 */
class ExecutionProcedure extends Model
{
    protected $fillable = ['execution_id', 'title', 'type', 'detail', 'status'];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    public function toData(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'detail' => $this->detail,
            'status' => $this->status,
        ];
    }
}
