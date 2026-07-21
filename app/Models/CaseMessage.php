<?php

namespace App\Models;

use App\Events\CaseMessageBroadcast;
use App\Support\Live;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseMessage extends Model
{
    protected $fillable = ['case_id', 'who', 'name', 'role', 'body', 'time_label'];

    // بثّ كل رسالة قضية لحظياً فور إنشائها (لا حاجة لاستدعاء يدوي في المتحكمات)
    protected static function booted(): void
    {
        static::created(function (CaseMessage $m) {
            Live::push(new CaseMessageBroadcast($m));
        });
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'case_id');
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
