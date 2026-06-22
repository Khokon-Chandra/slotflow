<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ScopesExistenceToTenant;
use App\Rules\ValidTimezone;
use Illuminate\Foundation\Http\FormRequest;

final class StoreBookingRequest extends FormRequest
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
            'staff_id' => ['required', 'integer', $this->existsInTenant('staff')],
            // ISO-8601 with an offset. "2026-09-01T09:00:00+02:00" and
            // "2026-09-01T07:00:00Z" are the same booking and are stored
            // identically; a bare "2026-09-01 09:00" is ambiguous and rejected.
            'starts_at' => ['required', 'date'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email:rfc', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_timezone' => ['required', 'string', new ValidTimezone],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
