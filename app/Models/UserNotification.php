<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    protected $fillable = ['user_id', 'icon', 'tone', 'body', 'time_label', 'is_read'];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.notifs)
    public function toData(): array
    {
        return [
            'ic' => $this->icon,
            'tone' => $this->tone,
            'text' => $this->body,
            // عمر حقيقي من created_at — كان time_label يُخزَّن «الآن» مرة واحدة فيبقى «الآن» للأبد
            'time' => $this->created_at?->locale('ar')->diffForHumans() ?: $this->time_label,
            'unread' => ! $this->is_read,
        ];
    }
}
