<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'driver_name' => ['nullable', 'string', 'max:255'],
            'vehicle_plate_number' => ['nullable', 'string', 'max:50'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
