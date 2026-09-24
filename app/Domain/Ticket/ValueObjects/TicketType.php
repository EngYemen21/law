<?php

declare(strict_types=1);

namespace App\Domain\Ticket\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * كائن قيمة يمثل نوع وتصنيف التذكرة القانونية (Ticket Type Value Object).
 * كائن غير قابل للتعديل (Immutable) وخالٍ تماماً من أي تبعيات لإطار العمل.
 */
final readonly class TicketType implements Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('نوع التذكرة أو الخدمة لا يمكن أن يكون فارغاً.');
        }

        if (mb_strlen($trimmed) > 128) {
            throw new InvalidArgumentException('نوع التذكرة لا يجب أن يتجاوز 128 حرفاً.');
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
