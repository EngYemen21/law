<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * نسخةٌ من تحليلٍ أو ملخّص — تُكتب وحدها عبر `ContentRevisions::record` ولا تُعدَّل ولا تُحذف.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $subject_ref
 * @property string $kind
 * @property int $version
 * @property string $source
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string|null $actor_role
 * @property string|null $ip
 * @property array<string, mixed> $content
 * @property Carbon $created_at
 */
class ContentRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'content' => 'array',
        'created_at' => 'datetime',
        'version' => 'integer',
    ];
}
