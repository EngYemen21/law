<?php

declare(strict_types=1);

namespace App\Domain\Ticket\Entities;

use App\Domain\Journey\Enums\TicketStatus;
use App\Domain\Ticket\ValueObjects\ClosureReason;
use App\Domain\Ticket\ValueObjects\TicketNumber;
use App\Domain\Ticket\ValueObjects\TicketType;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

/**
 * كائن الدومين الصافي للتذكرة (Pure Domain Entity).
 * مسؤول عن كبسولة قواعد الأعمال، الحالات، والصلاحيات الخاصة بالتذكرة دون أي اعتماد على Eloquent أو Frameworks.
 */
final class TicketEntity
{
    private ?int $id;

    private TicketNumber $number;

    private int $userId;

    private TicketType $type;

    private ?string $subject;

    private TicketStatus $status;

    private string $priority;

    private ?string $department;

    private ?int $assignedLawyerId;

    private ?string $assignedLawyerName;

    private string $tone;

    private bool $isFrozen;

    private ?ClosureReason $closureReason;

    private ?float $claimAmount;

    private ?string $courtName;

    private ?string $opponentName;

    private ?string $opponentId;

    private ?string $lastMessage;

    private ?int $legalDepartmentId;

    private ?int $legalServiceId;

    private ?DateTimeImmutable $createdAt;

    private ?DateTimeImmutable $updatedAt;

    public function __construct(
        TicketNumber $number,
        int $userId,
        TicketType $type,
        TicketStatus $status = TicketStatus::New,
        ?string $subject = null,
        string $priority = 'متوسطة',
        ?string $department = null,
        ?int $assignedLawyerId = null,
        ?string $assignedLawyerName = null,
        string $tone = 'b-blue',
        bool $isFrozen = false,
        ?ClosureReason $closureReason = null,
        ?float $claimAmount = null,
        ?string $courtName = null,
        ?string $opponentName = null,
        ?string $opponentId = null,
        ?string $lastMessage = null,
        ?int $legalDepartmentId = null,
        ?int $legalServiceId = null,
        ?int $id = null,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null
    ) {
        if ($userId <= 0) {
            throw new InvalidArgumentException('معرف العميل (userId) يجب أن يكون رقماً موجباً صحيحاً.');
        }

        $this->id = $id;
        $this->number = $number;
        $this->userId = $userId;
        $this->type = $type;
        $this->status = $status;
        $this->subject = $subject !== null ? trim($subject) : null;
        $this->priority = $priority;
        $this->department = $department;
        $this->assignedLawyerId = $assignedLawyerId;
        $this->assignedLawyerName = $assignedLawyerName;
        $this->tone = $tone;
        $this->isFrozen = $isFrozen;
        $this->closureReason = $closureReason;
        $this->claimAmount = $claimAmount;
        $this->courtName = $courtName;
        $this->opponentName = $opponentName;
        $this->opponentId = $opponentId;
        $this->lastMessage = $lastMessage;
        $this->legalDepartmentId = $legalDepartmentId;
        $this->legalServiceId = $legalServiceId;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    // ── Getters ──

    public function id(): ?int
    {
        return $this->id;
    }

    public function number(): TicketNumber
    {
        return $this->number;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function type(): TicketType
    {
        return $this->type;
    }

    public function subject(): ?string
    {
        return $this->subject;
    }

    public function status(): TicketStatus
    {
        return $this->status;
    }

    public function priority(): string
    {
        return $this->priority;
    }

    public function department(): ?string
    {
        return $this->department;
    }

    public function assignedLawyerId(): ?int
    {
        return $this->assignedLawyerId;
    }

    public function assignedLawyerName(): ?string
    {
        return $this->assignedLawyerName;
    }

    public function tone(): string
    {
        return $this->tone;
    }

    public function isFrozen(): bool
    {
        return $this->isFrozen;
    }

    public function closureReason(): ?ClosureReason
    {
        return $this->closureReason;
    }

    public function claimAmount(): ?float
    {
        return $this->claimAmount;
    }

    public function courtName(): ?string
    {
        return $this->courtName;
    }

    public function opponentName(): ?string
    {
        return $this->opponentName;
    }

    public function opponentId(): ?string
    {
        return $this->opponentId;
    }

    public function lastMessage(): ?string
    {
        return $this->lastMessage;
    }

    public function legalDepartmentId(): ?int
    {
        return $this->legalDepartmentId;
    }

    public function legalServiceId(): ?int
    {
        return $this->legalServiceId;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // ── Domain Invariants & Business Logic ──

    /** هل التذكرة في حالة نهائية قطعية؟ */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * تجميد السجل نهائياً عند التحويل لقضية أو تنفيذ أو إغلاق مسبب
     */
    public function freeze(): void
    {
        $this->isFrozen = true;
        $this->updatedAt = new DateTimeImmutable;
    }

    /**
     * إغلاق التذكرة إغلاقاً مسبباً برمز معتمد
     */
    public function close(ClosureReason $reason): void
    {
        if ($this->isFrozen) {
            throw new DomainException('لا يمكن إغلاق تذكرة مجمدة بالفعل.');
        }

        $this->closureReason = $reason;
        $this->status = TicketStatus::Closed;
        $this->tone = 'b-grey';
        $this->freeze();
    }

    /**
     * إسناد مستشار قانوني للتذكرة
     */
    public function assignLawyer(int $lawyerId, ?string $lawyerName = null): void
    {
        if ($this->isFrozen) {
            throw new DomainException('لا يمكن تعديل المحامي المسند لتذكرة مجمدة.');
        }

        if ($lawyerId <= 0) {
            throw new InvalidArgumentException('معرف المحامي يجب أن يكون رقماً موجباً صحيحاً.');
        }

        $this->assignedLawyerId = $lawyerId;
        if ($lawyerName !== null) {
            $this->assignedLawyerName = trim($lawyerName);
        }
        $this->updatedAt = new DateTimeImmutable;
    }

    /**
     * تغيير حالة التذكرة مع فحص الحصانة
     */
    public function transitionTo(TicketStatus $targetStatus, string $newTone): void
    {
        if ($this->isFrozen && ! in_array($this->status, [TicketStatus::Closed, TicketStatus::Completed], true)) {
            throw new DomainException('لا يمكن تغيير حالة تذكرة مجمدة.');
        }

        $this->status = $targetStatus;
        $this->tone = $newTone;

        if ($targetStatus->isTerminal()) {
            $this->freeze();
        } else {
            $this->updatedAt = new DateTimeImmutable;
        }
    }

    /**
     * حساب مصفوفة الإجراءات التقريرية للواجهة (Server-Driven Actions Matrix)
     */
    public function actionsMatrix(bool $hasExistingCase = false, bool $hasExistingExec = false): array
    {
        $isDecidingState = in_array($this->status, [TicketStatus::ReadyForOutcome, TicketStatus::Completed], true);
        $isOpinionState = $this->status === TicketStatus::LegalOpinion;

        return [
            'can_request_consult' => ! $this->isFrozen && in_array($this->status, [
                TicketStatus::LegalOpinion,
                TicketStatus::AwaitingBooking,
                TicketStatus::ReadyForOutcome,
            ], true),
            'can_convert_case' => ! $hasExistingCase && ($isDecidingState || $isOpinionState),
            'can_convert_exec' => ! $hasExistingExec && ($isDecidingState || $isOpinionState),
            'can_close' => ! $this->isFrozen && ($isDecidingState || $isOpinionState || $this->status === TicketStatus::AwaitingDocs),
            'can_request_docs' => ! $this->isFrozen && in_array($this->status, [
                TicketStatus::New,
                TicketStatus::Analyzing,
                TicketStatus::Referred,
                TicketStatus::AwaitingDocs,
            ], true),
            'can_rerun_ai' => ! $this->isFrozen && $this->status === TicketStatus::Analyzing,
        ];
    }

    /**
     * تحويل الكيان إلى مصفوفة بيانات خام (State Array)
     */
    public function toStateArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number->value(),
            'user_id' => $this->userId,
            'type' => $this->type->value(),
            'subject' => $this->subject,
            'status' => $this->status->value,
            'priority' => $this->priority,
            'department' => $this->department,
            'assigned_lawyer_id' => $this->assignedLawyerId,
            'assigned_lawyer' => $this->assignedLawyerName,
            'tone' => $this->tone,
            'is_frozen' => $this->isFrozen,
            'closure_reason' => $this->closureReason?->toArray(),
            'claim_amount' => $this->claimAmount,
            'court_name' => $this->courtName,
            'opponent_name' => $this->opponentName,
            'opponent_id' => $this->opponentId,
            'last_message' => $this->lastMessage,
            'legal_department_id' => $this->legalDepartmentId,
            'legal_service_id' => $this->legalServiceId,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * بناء الكيان من مصفوفة بيانات
     */
    public static function fromState(array $state): self
    {
        $closureReason = null;
        if (! empty($state['closure_reason_code'])) {
            $closureReason = new ClosureReason(
                code: $state['closure_reason_code'],
                notes: $state['closure_notes'] ?? null,
                closedById: isset($state['closed_by_id']) ? (int) $state['closed_by_id'] : null
            );
        }

        $createdAt = ! empty($state['created_at'])
            ? new DateTimeImmutable((string) $state['created_at'])
            : null;

        $updatedAt = ! empty($state['updated_at'])
            ? new DateTimeImmutable((string) $state['updated_at'])
            : null;

        $status = $state['status'] instanceof TicketStatus
            ? $state['status']
            : (TicketStatus::tryFrom((string) ($state['status'] ?? '')) ?? TicketStatus::New);

        return new self(
            number: new TicketNumber((string) ($state['number'] ?? '')),
            userId: (int) ($state['user_id'] ?? 0),
            type: new TicketType((string) ($state['type'] ?? 'استشارة عامة')),
            status: $status,
            subject: $state['subject'] ?? null,
            priority: $state['priority'] ?? 'متوسطة',
            department: $state['department'] ?? null,
            assignedLawyerId: isset($state['assigned_lawyer_id']) ? (int) $state['assigned_lawyer_id'] : null,
            assignedLawyerName: $state['assigned_lawyer'] ?? null,
            tone: $state['tone'] ?? 'b-blue',
            isFrozen: (bool) ($state['is_frozen'] ?? false),
            closureReason: $closureReason,
            claimAmount: isset($state['claim_amount']) ? (float) $state['claim_amount'] : null,
            courtName: $state['court_name'] ?? null,
            opponentName: $state['opponent_name'] ?? null,
            opponentId: $state['opponent_id'] ?? null,
            lastMessage: $state['last_message'] ?? null,
            legalDepartmentId: isset($state['legal_department_id']) ? (int) $state['legal_department_id'] : null,
            legalServiceId: isset($state['legal_service_id']) ? (int) $state['legal_service_id'] : null,
            id: isset($state['id']) ? (int) $state['id'] : null,
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }
}
