<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBookingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('booking')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(BookingStatus::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function status(): BookingStatus
    {
        return BookingStatus::from($this->string('status')->toString());
    }
}
