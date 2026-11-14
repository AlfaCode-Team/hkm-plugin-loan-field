<?php

declare(strict_types=1);

namespace Plugins\LoanField\Domain\Rules;

/**
 * A visit or a reminder cannot be recorded as having happened in the future.
 *
 * One day of tolerance: a handset's local date can run a day ahead of the
 * server's UTC date, so a visit made at 01:00 in Kampala is "tomorrow" in UTC.
 * Anything further ahead is a typo or a clock, and is refused.
 */
final class RecordingWindow
{
    private const int TOLERANCE_SECONDS = 86_400;

    /** @throws \DomainException */
    public static function assertNotFuture(\DateTimeImmutable $when, \DateTimeImmutable $now, string $what): void
    {
        if ($when->getTimestamp() > $now->getTimestamp() + self::TOLERANCE_SECONDS) {
            throw new \DomainException(sprintf('The %s cannot be in the future.', $what));
        }
    }
}
