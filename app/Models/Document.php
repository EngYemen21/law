<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    protected $fillable = [
        'user_id', 'name', 'meta', 'direction',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.docsOut / DATA.docsUp)
    public function toCard(): array
    {
        return [
            'name' => $this->name,
            'meta' => $this->meta,
        ];
    }
}
