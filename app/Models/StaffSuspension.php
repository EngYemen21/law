<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * فترة إيقاف موظّف — `ends_on` فارغٌ ما دام موقوفاً. يكتبها `User::setSuspended()` وحده، ويقرؤها
 * `Finance\StaffEarnings` ليُسقط أيّام الإيقاف من الراتب.
 *
 * @property int $user_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 */
class StaffSuspension extends Model
{
    protected $fillable = ['user_id', 'starts_on', 'ends_on'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];
}
