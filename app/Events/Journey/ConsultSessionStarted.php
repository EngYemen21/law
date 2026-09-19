<?php

namespace App\Events\Journey;

use App\Models\Consult;
use Illuminate\Foundation\Events\Dispatchable;

final class ConsultSessionStarted
{
    use Dispatchable;

    public function __construct(public readonly Consult $consult) {}
}
