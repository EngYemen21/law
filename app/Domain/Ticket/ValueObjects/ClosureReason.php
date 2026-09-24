<?php

declare(strict_types=1);

namespace App\Domain\Ticket\ValueObjects;

use App\Domain\Journey\Enums\ClosureReasonCode;
use InvalidArgumentException;

/**
 * كائن قيمة يمثل تسبيب إغلاق التذكرة (Closure Reason Value Object).
 * كائن غير قابل للتعديل (Immutable) وخالٍ تماماً من أي تبعيات لإطار العمل.
 */
final readonly class ClosureReason
{
    private ClosureReasonCode $code;
    private ?string $notes;
    private ?int $closedById;

    public function __construct(ClosureReasonCode|string $code, ?string $notes = null, ?int $closedById = null)
    {
        if (is_string($code)) {
            $enumCase = ClosureReasonCode::tryFrom($code);
            if ($enumCase === null) {
                throw new InvalidArgumentException("رمز سبب الإغلاق '{$code}' غير معتمد نظامياً.");
            }
            $this->code = $enumCase;
        } else {
            $this->code = $code;
        }

        $trimmedNotes = $notes !== null ? trim($notes) : null;
        if ($this->code === ClosureReasonCode::OtherWithReason && ($trimmedNotes === null || $trimmedNotes === '')) {
            throw new InvalidArgumentException('يجب تقديم تسبيب مفصل عند اختيار سبب الإغلاق: "سبب نظامي آخر".');
        }

        $this->notes = $trimmedNotes;
        $this->closedById = $closedById;
    }

    public static function create(ClosureReasonCode|string $code, ?string $notes = null, ?int $closedById = null): self
    {
        return new self($code, $notes, $closedById);
    }

    public function code(): ClosureReasonCode
    {
        return $this->code;
    }

    public function codeValue(): string
    {
        return $this->code->value;
    }

    public function label(): string
    {
        return $this->code->label();
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function closedById(): ?int
    {
        return $this->closedById;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code
            && $this->notes === $other->notes
            && $this->closedById === $other->closedById;
    }

    /**
     * @return array{code: string, label: string, notes: ?string, closed_by_id: ?int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'label' => $this->code->label(),
            'notes' => $this->notes,
            'closed_by_id' => $this->closedById,
        ];
    }
}
