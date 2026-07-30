<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Rules\ValidTimezone;
use Illuminate\Foundation\Http\FormRequest;

final class BookingAssistantRequest extends FormRequest
{
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
            // Capped at 400 characters: this is a booking request, not a
            // conversation. A cap is also the cheapest defence against
            // someone using an unauthenticated endpoint as free inference.
            'text' => ['required', 'string', 'min:2', 'max:400'],
            'tz' => ['required', 'string', new ValidTimezone],
            'limit' => ['nullable', 'integer', 'between:1,12'],
        ];
    }
}
