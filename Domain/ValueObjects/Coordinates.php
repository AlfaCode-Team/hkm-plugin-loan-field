<?php

declare(strict_types=1);

namespace Plugins\LoanField\Domain\ValueObjects;

/**
 * A GPS fix, or the absence of one.
 *
 * A visit in a valley with no fix is still a real visit, so a visit may carry
 * NO coordinates at all. What it may not carry is a half-fix, or a figure that
 * is not a latitude and longitude. A swapped pair is the dangerous case: it is
 * still a valid pair of numbers, so it passes the range check and lands the
 * visit somewhere plausible-looking but wrong. A country bounding box would
 * catch swaps; it is not enforced here because border customers exist, and
 * that is a business decision, not a kernel one.
 *
 * (0, 0) is refused as well. It is what a handset reports when it has no fix
 * and fills the field with zeroes; the honest value is null.
 */
final readonly class Coordinates
{
    private function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }

    /** @throws \DomainException */
    public static function of(float $latitude, float $longitude): self
    {
        if (!is_finite($latitude) || $latitude < -90.0 || $latitude > 90.0) {
            throw new \DomainException('Latitude must be between -90 and 90 degrees.');
        }

        if (!is_finite($longitude) || $longitude < -180.0 || $longitude > 180.0) {
            throw new \DomainException('Longitude must be between -180 and 180 degrees.');
        }

        if ($latitude === 0.0 && $longitude === 0.0) {
            throw new \DomainException('A latitude and longitude of 0, 0 is an empty fix; send null instead.');
        }

        // decimal(10,7) in the schema: keep no more precision than the column holds.
        return new self(round($latitude, 7), round($longitude, 7));
    }

    /**
     * Both null is "no fix". Exactly one null is refused.
     *
     * @throws \DomainException
     */
    public static function optional(?float $latitude, ?float $longitude): ?self
    {
        if ($latitude === null && $longitude === null) {
            return null;
        }

        if ($latitude === null || $longitude === null) {
            throw new \DomainException('Latitude and longitude must be given together, or not at all.');
        }

        return self::of($latitude, $longitude);
    }
}
