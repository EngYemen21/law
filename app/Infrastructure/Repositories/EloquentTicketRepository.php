<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Domain\Ticket\Entities\TicketEntity;
use App\Domain\Ticket\Repositories\TicketRepositoryInterface;
use App\Domain\Ticket\ValueObjects\TicketNumber;
use App\Models\Ticket;
use DateTimeImmutable;

/**
 * تطبيق مستودع التذاكر عبر Eloquent (Infrastructure Layer).
 * يقوم بالتحويل المتبادل بين كائن الدومين الصافي TicketEntity ونموذج Eloquent Ticket.
 */
class EloquentTicketRepository implements TicketRepositoryInterface
{
    public function findById(int $id): ?TicketEntity
    {
        /** @var Ticket|null $model */
        $model = Ticket::find($id);

        return $model !== null ? $this->toEntity($model) : null;
    }

    public function findByNumber(TicketNumber $number): ?TicketEntity
    {
        /** @var Ticket|null $model */
        $model = Ticket::where('number', $number->value())->first();

        return $model !== null ? $this->toEntity($model) : null;
    }

    public function lockForUpdate(int $id): ?TicketEntity
    {
        /** @var Ticket|null $model */
        $model = Ticket::whereKey($id)->lockForUpdate()->first();

        return $model !== null ? $this->toEntity($model) : null;
    }

    public function save(TicketEntity $ticket): void
    {
        $state = $ticket->toStateArray();

        $attributes = [
            'number' => $state['number'],
            'user_id' => $state['user_id'],
            'type' => $state['type'],
            'subject' => $state['subject'],
            'status' => $state['status'],
            'priority' => $state['priority'],
            'department' => $state['department'],
            'assigned_lawyer_id' => $state['assigned_lawyer_id'],
            'assigned_lawyer' => $state['assigned_lawyer'],
            'tone' => $state['tone'],
            'is_frozen' => $state['is_frozen'],
            'closure_reason_code' => $state['closure_reason']['code'] ?? null,
            'closure_notes' => $state['closure_reason']['notes'] ?? null,
            'closed_by_id' => $state['closure_reason']['closed_by_id'] ?? null,
            'claim_amount' => $state['claim_amount'],
            'court_name' => $state['court_name'],
            'opponent_name' => $state['opponent_name'],
            'opponent_id' => $state['opponent_id'],
            'last_message' => $state['last_message'],
            'legal_department_id' => $state['legal_department_id'],
            'legal_service_id' => $state['legal_service_id'],
        ];

        if ($ticket->id() !== null) {
            Ticket::whereKey($ticket->id())->update($attributes);
        } else {
            Ticket::create($attributes);
        }
    }

    public function toEntity(Ticket $model): TicketEntity
    {
        return TicketEntity::fromState([
            'id' => $model->id,
            'number' => $model->number,
            'user_id' => $model->user_id,
            'type' => $model->type,
            'subject' => $model->subject,
            'status' => $model->status,
            'priority' => $model->priority,
            'department' => $model->department,
            'assigned_lawyer_id' => $model->assigned_lawyer_id,
            'assigned_lawyer' => $model->assigned_lawyer,
            'tone' => $model->tone,
            'is_frozen' => (bool) $model->is_frozen,
            'closure_reason_code' => $model->closure_reason_code,
            'closure_notes' => $model->closure_notes,
            'closed_by_id' => $model->closed_by_id,
            'claim_amount' => $model->claim_amount,
            'court_name' => $model->court_name,
            'opponent_name' => $model->opponent_name,
            'opponent_id' => $model->opponent_id,
            'last_message' => $model->last_message,
            'legal_department_id' => $model->legal_department_id,
            'legal_service_id' => $model->legal_service_id,
            'created_at' => $model->created_at?->format(DateTimeImmutable::ATOM),
            'updated_at' => $model->updated_at?->format(DateTimeImmutable::ATOM),
        ]);
    }
}
