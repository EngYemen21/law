<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجلّ انتقالات الرحلة — صفٌّ لكلّ انتقالٍ نفّذه `Workflow`، لا يُعدَّل ولا يُحذف.
 * يغذّي الخطّ الزمنيّ والتدقيق، ويُجيب عن «من نقل الملفّ إلى هنا ومتى ولماذا».
 */
class JourneyTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'entity_type', 'entity_id', 'entity_ref', 'transition',
        'from_state', 'to_state', 'actor_id', 'reason', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * **هل وقع هذا الانتقال لهذا الكيان من قبل؟** — لحدثٍ يقع مرّةً واحدة في عمر الكيان
     * (تنبيه الجلسة المنسيّة) فيكون سطرُ الرحلة نفسُه ذاكرةَ «وقع» بلا عمودٍ موازٍ يتباعد عنه.
     */
    public static function happened(Model $entity, string $transition): bool
    {
        return self::where('entity_type', class_basename($entity))
            ->where('entity_id', $entity->getKey())
            ->where('transition', $transition)
            ->exists();
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
