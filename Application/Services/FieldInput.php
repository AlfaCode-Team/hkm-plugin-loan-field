<?php

declare(strict_types=1);

namespace Plugins\LoanField\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use Plugins\LoanField\Domain\ValueObjects\ReminderChannel;
use Plugins\LoanField\Domain\ValueObjects\VisitOutcome;
use Plugins\LoanField\Domain\ValueObjects\VisitType;

/**
 * Turns the raw request into the types the domain takes.
 *
 * It REFUSES what cannot be what it claims rather than coercing it: a float is
 * not a whole shilling count, a lone latitude is not a fix, an unknown outcome
 * is not "other". The domain then checks what the values mean.
 *
 * Calendar dates are read in the business timezone (a visit "on the 14th" is
 * the 14th in Kampala). Instants are converted to UTC, which is how the column
 * is stored and how the app reads it back.
 */
final class FieldInput
{
    private const int MAX_PER_PAGE = 100;

    private const string UTC = 'UTC';

    /** @param array<string, mixed> $in */
    public static function visit(array $in, \DateTimeZone $zone, \DateTimeImmutable $now): array
    {
        return [
            'customer_id' => self::optionalId($in, 'customer_id'),
            'loan_id'     => self::optionalId($in, 'loan_id'),
            'visit_type'  => self::enum($in, 'visit_type', VisitType::class, 'a visit type'),
            'visited_on'  => self::optionalDate($in, 'visited_on', $zone)
                ?? $now->setTimezone($zone)->setTime(0, 0),
            'visited_at'  => self::optionalDateTime($in, 'visited_at', $zone),
            'outcome'     => self::optionalEnum($in, 'outcome', VisitOutcome::class, 'an outcome'),
            'notes'       => self::optionalString($in, 'notes', 5_000),
            'latitude'    => self::optionalCoordinate($in, 'latitude'),
            'longitude'   => self::optionalCoordinate($in, 'longitude'),
            'photo_path'  => self::optionalString($in, 'photo_path', 255),
        ];
    }

    /** @param array<string, mixed> $in */
    public static function visitFilters(array $in, \DateTimeZone $zone): array
    {
        $from = self::optionalDate($in, 'from', $zone);
        $to   = self::optionalDate($in, 'to', $zone);

        return [
            'customer_id' => self::optionalId($in, 'customer_id'),
            'loan_id'     => self::optionalId($in, 'loan_id'),
            'recorded_by' => self::optionalId($in, 'recorded_by'),
            'visit_type'  => self::optionalEnum($in, 'visit_type', VisitType::class, 'a visit type')?->value,
            'outcome'     => self::optionalEnum($in, 'outcome', VisitOutcome::class, 'an outcome')?->value,
            'from'        => $from?->format('Y-m-d'),
            'to'          => $to?->format('Y-m-d'),
        ] + self::page($in);
    }

    /** @param array<string, mixed> $in */
    public static function reminder(array $in, \DateTimeZone $zone, \DateTimeImmutable $now): array
    {
        return [
            'customer_id'   => self::requiredId($in, 'customer_id'),
            'loan_id'       => self::optionalId($in, 'loan_id'),
            'channel'       => self::enum($in, 'channel', ReminderChannel::class, 'a channel'),
            'amount_quoted' => self::optionalShillings($in, 'amount_quoted'),
            'message'       => self::optionalString($in, 'message', 1_000),
            'sent_at'       => self::optionalDateTime($in, 'sent_at', $zone) ?? $now,
        ];
    }

    /**
     * `from` and `to` are inclusive business days. The result is the UTC
     * half-open interval [from 00:00 local, day after to 00:00 local).
     *
     * @param  array<string, mixed> $in
     * @return array{customer_id: ?int, loan_id: ?int, from: ?string, to: ?string, page: int, per_page: int}
     */
    public static function reminderFilters(array $in, \DateTimeZone $zone): array
    {
        $from = self::optionalDate($in, 'from', $zone);
        $to   = self::optionalDate($in, 'to', $zone);
        $utc  = new \DateTimeZone(self::UTC);

        return [
            'customer_id' => self::optionalId($in, 'customer_id'),
            'loan_id'     => self::optionalId($in, 'loan_id'),
            'from'        => $from?->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
            'to'          => $to?->modify('+1 day')->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s'),
        ] + self::page($in);
    }

    /**
     * @param  array<string, mixed> $in
     * @return array{days: int, field_officer_id: ?int, page: int, per_page: int}
     */
    public static function contacts(array $in): array
    {
        $days = self::optionalId($in, 'days') ?? 14;

        if ($days > 365) {
            throw self::bad('days', 'a whole number of days from 1 to 365');
        }

        return [
            'days'             => $days,
            'field_officer_id' => self::optionalId($in, 'field_officer_id'),
        ] + self::page($in);
    }

    /** @param array<string, mixed> $in  @return array{page: int, per_page: int} */
    public static function page(array $in): array
    {
        return [
            'page'     => max(1, self::optionalInt($in, 'page') ?? 1),
            'per_page' => min(self::MAX_PER_PAGE, max(1, self::optionalInt($in, 'per_page') ?? 25)),
        ];
    }

    /** @param array<string, mixed> $in */
    private static function requiredId(array $in, string $key): int
    {
        return self::optionalId($in, $key) ?? throw self::bad($key, 'required');
    }

    /** A positive whole number, or absent. @param array<string, mixed> $in */
    private static function optionalId(array $in, string $key): ?int
    {
        $value = self::optionalInt($in, $key);

        if ($value !== null && $value < 1) {
            throw self::bad($key, 'a positive whole number');
        }

        return $value;
    }

    /** A whole number. Floats are refused, so "1000.5" never becomes a figure. @param array<string, mixed> $in */
    private static function optionalInt(array $in, string $key): ?int
    {
        if (!array_key_exists($key, $in) || $in[$key] === null || $in[$key] === '') {
            return null;
        }

        $value = $in[$key];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw self::bad($key, 'a whole number');
    }

    /** Whole UGX, never a float. @param array<string, mixed> $in */
    private static function optionalShillings(array $in, string $key): ?int
    {
        $value = self::optionalInt($in, $key);

        if ($value !== null && $value < 0) {
            throw self::bad($key, 'a whole number of shillings, zero or more');
        }

        return $value;
    }

    /**
     * Latitude or longitude: a JSON number or a numeric string. The range is
     * the domain's check (Coordinates), not this parser's.
     *
     * @param array<string, mixed> $in
     */
    private static function optionalCoordinate(array $in, string $key): ?float
    {
        if (!array_key_exists($key, $in) || $in[$key] === null || $in[$key] === '') {
            return null;
        }

        $value = $in[$key];

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw self::bad($key, 'a decimal number of degrees');
    }

    /** @param array<string, mixed> $in */
    private static function optionalString(array $in, string $key, int $max): ?string
    {
        if (!isset($in[$key]) || $in[$key] === '') {
            return null;
        }

        if (!is_string($in[$key])) {
            throw self::bad($key, 'text');
        }

        if (mb_strlen($in[$key]) > $max) {
            throw self::bad($key, 'at most ' . $max . ' characters');
        }

        return $in[$key];
    }

    /**
     * @template T of \BackedEnum
     * @param  array<string, mixed> $in
     * @param  class-string<T> $enum
     * @return T
     */
    private static function enum(array $in, string $key, string $enum, string $expected): \BackedEnum
    {
        return self::optionalEnum($in, $key, $enum, $expected) ?? throw self::bad($key, 'required: ' . $expected);
    }

    /**
     * @template T of \BackedEnum
     * @param  array<string, mixed> $in
     * @param  class-string<T> $enum
     * @return T|null
     */
    private static function optionalEnum(array $in, string $key, string $enum, string $expected): ?\BackedEnum
    {
        if (!isset($in[$key]) || $in[$key] === '') {
            return null;
        }

        $case = is_string($in[$key]) ? $enum::tryFrom($in[$key]) : null;

        if ($case === null) {
            $names = implode(', ', array_map(static fn(\BackedEnum $c): string => (string) $c->value, $enum::cases()));

            throw self::bad($key, $expected . ', one of: ' . $names);
        }

        return $case;
    }

    /**
     * A calendar date, Y-m-d, read in the business timezone.
     *
     * @param array<string, mixed> $in
     */
    private static function optionalDate(array $in, string $key, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (!isset($in[$key]) || $in[$key] === '') {
            return null;
        }

        $text = (string) $in[$key];
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, $zone);

        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw self::bad($key, 'a date as YYYY-MM-DD');
        }

        return $date;
    }

    /**
     * An instant, converted to UTC. A string with no offset is read as business
     * local time.
     *
     * @param array<string, mixed> $in
     */
    private static function optionalDateTime(array $in, string $key, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (!isset($in[$key]) || $in[$key] === '') {
            return null;
        }

        try {
            $at = new \DateTimeImmutable((string) $in[$key], $zone);
        } catch (\Exception) {
            throw self::bad($key, 'a date and time');
        }

        return $at->setTimezone(new \DateTimeZone(self::UTC));
    }

    private static function bad(string $field, string $expected): ServiceException
    {
        return new ServiceException(
            sprintf('%s must be %s.', $field, $expected),
            layer: 'service.field',
            context: ['field' => $field],
        );
    }
}
