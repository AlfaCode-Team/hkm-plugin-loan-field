<?php

declare(strict_types=1);

namespace Plugins\LoanField\Domain\ValueObjects;

/**
 * The figure the customer was told, in whole Ugandan shillings.
 *
 * Integer by construction: a float would let "1000.5" reach a receipt-facing
 * record. It is stored as quoted even if the balance later changes, because a
 * reminder is a record of what was SAID, not a view of the current balance.
 */
final readonly class QuotedAmount
{
    private function __construct(public int $shillings)
    {
    }

    /** @throws \DomainException */
    public static function ugx(int $shillings): self
    {
        if ($shillings < 0) {
            throw new \DomainException('A quoted amount cannot be negative.');
        }

        return new self($shillings);
    }
}
