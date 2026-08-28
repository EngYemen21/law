<?php

namespace App\Models;

use App\Models\Concerns\PurgesStoredFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use PurgesStoredFile;

    protected $fillable = [
        'user_id', 'name', 'meta', 'direction', 'path', 'mime', 'size',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // الشكل الذي تتوقعه الواجهة (يطابق DATA.docsOut / DATA.docsUp)
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'meta' => $this->meta,
            'canDownload' => $this->path !== null,   // زر التنزيل يظهر فقط لِما له ملفّ فعليّ
        ];
    }
}
