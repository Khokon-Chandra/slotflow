<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Short, human-quotable booking codes: "BL-7Q4M2X".
 *
 * Deliberately not the primary key. Sequential integers in a URL tell the
 * world how many bookings you take and invite enumeration; a reference is
 * safe to read out over the phone and safe to put in a link.
 *
 * The alphabet omits I, O, 0 and 1 — the characters people mishear and
 * mistype when reading a code aloud.
 */
final class BookingReference
{
    private const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const int LENGTH = 6;

    public static function generate(Tenant $tenant): string
    {
        $prefix = self::prefixFor($tenant);

        // Collisions are unlikely (32^6 ≈ 1.07e9 per tenant) but "unlikely"
        // is not "impossible", and the column is unique.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $reference = $prefix.'-'.self::randomCode();

            $exists = Booking::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('reference', $reference)
                ->exists();

            if (! $exists) {
                return $reference;
            }
        }

        // Ten collisions in a row means something is very wrong with the RNG;
        // fall back to something that cannot collide.
        return $prefix.'-'.strtoupper(Str::random(10));
    }

    private static function prefixFor(Tenant $tenant): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $tenant->name) ?? '';

        return strtoupper(substr($letters !== '' ? $letters : 'SF', 0, 2));
    }

    private static function randomCode(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
