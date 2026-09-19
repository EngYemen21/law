<?php

namespace App\Events\Journey;

use App\Models\Consult;
use Illuminate\Foundation\Events\Dispatchable;

final class ConsultCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly Consult $consult,
        public readonly string $from,
        public readonly string $actorName,
        public readonly ?string $reason = null,
    ) {}
}

