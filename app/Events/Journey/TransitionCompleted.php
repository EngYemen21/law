<?php

namespace App\Events\Journey;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** انتقالٌ التزم — يُطلقه `Workflow` بعد الالتزام لكلّ انتقال. */
final class TransitionCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $transition,
        public readonly Model $entity,
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $actorId,
    ) {}
}
