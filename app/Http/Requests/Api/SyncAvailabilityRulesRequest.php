<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Replaces a staff member's whole weekly pattern in one call.
 *
 * A PUT of the complete set rather than per-row POST/DELETE, because a week's
 * hours are edited as a unit. It also makes the operation idempotent: the same
 * payload sent twice leaves the same schedule.
 */
final class SyncAvailabilityRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageAvailability', $this->route('staff')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rules' => ['present', 'array', 'max:40'],
            'rules.*.weekday' => ['required', 'integer', 'between:0,6'],
            'rules.*.starts_at' => ['required', 'date_format:H:i'],
            'rules.*.ends_at' => ['required', 'date_format:H:i'],
            'rules.*.effective_from' => ['nullable', 'date_format:Y-m-d'],
            'rules.*.effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:rules.*.effective_from'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /** @var list<array{weekday: int, starts_at: string, ends_at: string}> $rules */
            $rules = $this->input('rules', []);

            foreach ($rules as $index => $rule) {
                // Equal times are always a mistake. `ends_at < starts_at` is
                // not — it means an overnight shift, which the engine supports.
                if ($rule['starts_at'] === $rule['ends_at']) {
                    $validator->errors()->add(
                        "rules.{$index}.ends_at",
                        'A shift must be longer than zero minutes.',
                    );
                }
            }
        });
    }
}
