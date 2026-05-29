<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts an IANA identifier ("Europe/Vienna"), and only that.
 *
 * Rejecting "+02:00" and "CEST" is deliberate. A fixed offset cannot express
 * daylight saving, and an abbreviation is ambiguous — "CST" is three different
 * zones. Both look like they work right up until the clocks change.
 */
final class ValidTimezone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be an IANA timezone identifier, for example Europe/Vienna.');

            return;
        }

        if (! in_array($value, DateTimeZone::listIdentifiers(), true)) {
            $fail('The :attribute must be an IANA timezone identifier such as Europe/Vienna or Asia/Dhaka. Offsets and abbreviations are not accepted.');
        }
    }
}
