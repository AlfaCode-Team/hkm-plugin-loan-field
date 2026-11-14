<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanField;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\LoanField\Domain\Rules\RecordingWindow;
use Plugins\LoanField\Domain\ValueObjects\Coordinates;
use Plugins\LoanField\Domain\ValueObjects\QuotedAmount;
use Plugins\LoanField\Domain\ValueObjects\ReminderChannel;
use Plugins\LoanField\Domain\ValueObjects\VisitOutcome;
use Plugins\LoanField\Domain\ValueObjects\VisitType;

/** The rules that need no database: they are the domain, and it has no dependencies. */
#[CoversClass(Coordinates::class)]
#[CoversClass(QuotedAmount::class)]
#[CoversClass(RecordingWindow::class)]
final class DomainTest extends TestCase
{
    public function test_a_valid_fix_is_kept_to_the_column_precision(): void
    {
        $c = Coordinates::of(0.3476123456, 32.5825987654);

        self::assertSame(0.3476123, $c->latitude);
        self::assertSame(32.5825988, $c->longitude);
    }

    #[DataProvider('outOfRange')]
    public function test_an_out_of_range_coordinate_is_refused(float $lat, float $lng): void
    {
        $this->expectException(\DomainException::class);

        Coordinates::of($lat, $lng);
    }

    /** @return iterable<string, array{float, float}> */
    public static function outOfRange(): iterable
    {
        // Each of these is a figure that cannot be a latitude or longitude at all.
        yield 'latitude above 90'          => [91.0, 32.6];
        yield 'latitude below -90'         => [-90.5, 32.6];
        yield 'longitude above 180'        => [0.3, 180.5];
        yield 'longitude below -180'       => [0.3, -181.0];
        yield 'NaN latitude'              => [NAN, 32.6];
        yield 'infinite longitude'        => [0.3, INF];
        yield 'empty fix reported as 0,0' => [0.0, 0.0];
    }

    public function test_a_visit_with_no_fix_has_no_coordinates(): void
    {
        self::assertNull(Coordinates::optional(null, null));
    }

    public function test_a_half_fix_is_refused(): void
    {
        $this->expectException(\DomainException::class);

        Coordinates::optional(0.3476, null);
    }

    public function test_a_negative_amount_quoted_is_refused(): void
    {
        $this->expectException(\DomainException::class);

        QuotedAmount::ugx(-1);
    }

    public function test_a_future_visit_is_refused_but_one_day_of_clock_skew_is_not(): void
    {
        $now = new \DateTimeImmutable('2026-11-14 09:00:00', new \DateTimeZone('UTC'));

        RecordingWindow::assertNotFuture(new \DateTimeImmutable('2026-11-15 06:00:00', new \DateTimeZone('UTC')), $now, 'visit date');

        $this->expectException(\DomainException::class);

        RecordingWindow::assertNotFuture(new \DateTimeImmutable('2026-11-16 00:00:00', new \DateTimeZone('UTC')), $now, 'visit date');
    }

    public function test_an_unknown_outcome_or_channel_is_not_an_enum_case(): void
    {
        self::assertNull(VisitOutcome::tryFrom('happy'));
        self::assertNull(ReminderChannel::tryFrom('telegram'));
        self::assertNull(VisitType::tryFrom('collections'), 'the plural is a typo, not a second type');
        self::assertSame(ReminderChannel::WhatsApp, ReminderChannel::tryFrom('whatsapp'));
    }
}
