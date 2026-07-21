<?php

namespace App\Http\Controllers\Concerns;

trait UsesClock
{
    protected function clock(): string
    {
        $now = now();

        return $now->format('h:i').' '.($now->hour < 12 ? 'ص' : 'م');
    }
}
