<?php

namespace App\Domain\Journey;

use Illuminate\Database\Eloquent\Model;

/** يربط نموذجاً من كيانات الرحلة بـ`StateWriteGuard`. */
trait GuardsJourneyState
{
    public static function bootGuardsJourneyState(): void
    {
        static::saving(function (Model $model) {
            StateWriteGuard::inspect($model);
        });
    }
}
