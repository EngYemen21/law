<?php

namespace App\Models;

use App\Enums\DocumentDirection;
use App\Models\Concerns\PurgesStoredFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $meta
 * @property DocumentDirection $direction
 * @property string|null $path
 */
class Document extends Model
{
    use PurgesStoredFile;

    protected $fillable = [
        'user_id', 'name', 'meta', 'direction', 'path', 'mime', 'size',
    ];

    protected function casts(): array
    {
        return ['direction' => DocumentDirection::class];
    }

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
