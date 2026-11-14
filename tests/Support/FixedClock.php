<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanField\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;

/** A clock the test moves by hand. "In the last N days" depends on today. */
final class FixedClock implements ClockPort
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function set(string $when): void
    {
        $this->now = new \DateTimeImmutable($when);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }
}
