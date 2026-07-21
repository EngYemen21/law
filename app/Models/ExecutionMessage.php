<?php

namespace App\Models;

use App\Events\ExecMessageBroadcast;
use App\Support\Live;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionMessage extends Model
{
    protected $fillable = ['execution_id', 'who', 'name', 'role', 'body', 'time_label'];

    // بثّ كل رسالة تنفيذ لحظياً فور إنشائها (كـ CaseMessage)
    protected static function booted(): void
    {
        static::created(function (ExecutionMessage $m) {
            Live::push(new ExecMessageBroadcast($m));
        });
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
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
