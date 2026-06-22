<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ScopesExistenceToTenant;
use App\Rules\ValidTimezone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AvailabilityRequest extends FormRequest
{
    use ScopesExistenceToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', $this->existsInTenant('services')],
            'date' => ['required_without:from', 'date_format:Y-m-d'],
            'from' => ['required_without:date', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Required, not optional with a default. A booking API that
            // guesses the caller's timezone is a booking API that is
            // occasionally an hour wrong, and never says so.
            'tz' => ['required', 'string', new ValidTimezone],
            'staff_id' => ['nullable', 'integer', $this->existsInTenant('staff')],
        ];
    }

    public function messages(): array
    {
        return [
            'tz.required' => 'A timezone is required, e.g. tz=Europe/Vienna. Slots are returned in it.',
        ];
    }

    /**
     * Cap how far one call may scan.
     *
     * The engine expands rules day by day for every eligible staff member, so
     * an unbounded range is an expensive query dressed up as a feature. The
     * limit belongs here rather than deeper down: caught in validation it is a
     * 422 the caller can act on, caught in the engine it is a 500.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $from = $this->date('from') ?? $this->date('date');
                $until = $this->date('until');

                if ($from === null || $until === null) {
                    return;
                }

                $maxDays = (int) config('slotflow.availability.max_range_days', 31);

                if ($from->diffInDays($until) > $maxDays) {
                    $validator->errors()->add(
                        'until',
                        "An availability search may cover at most {$maxDays} days. Ask for a narrower range.",
                    );
                }
            },
        ];
    }
}
