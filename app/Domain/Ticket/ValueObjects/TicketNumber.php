<?php

declare(strict_types=1);

namespace App\Domain\Ticket\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * كائن قيمة يمثل الرقم المرجعي للتذكرة (Ticket Number Value Object).
 * كائن غير قابل للتعديل (Immutable) وخالٍ تماماً من أي تبعيات لإطار العمل.
 */
final readonly class TicketNumber implements Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('رقم التذكرة لا يمكن أن يكون فارغاً.');
        }

        if (mb_strlen($trimmed) > 64) {
            throw new InvalidArgumentException('رقم التذكرة لا يمكن أن يتجاوز 64 حرفاً.');
        }

        $this->value = $trimmed;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
