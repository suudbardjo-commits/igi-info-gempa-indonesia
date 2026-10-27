<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Lokasi perangkat penerima wajib: dipakai untuk geofence dan dicatat di jejak audit.
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'received' => ['nullable', 'array'],
            'received.*.dispatch_item_id' => ['required', 'uuid'],
            'received.*.quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'geofence_override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
