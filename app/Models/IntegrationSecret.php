<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مفتاح خدمةٍ خارجيّة ضبطته الإدارة من الشاشة — **`value` مشفّرة** بمفتاح التطبيق (`encrypted`)، فنسخةٌ مسرّبة
 * من القاعدة بلا `.env` لا تكشفها. لا يُكتب ولا يُقرأ إلّا عبر `Support\Integrations\IntegrationSecrets`.
 */
class IntegrationSecret extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = ['value' => 'encrypted'];

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
