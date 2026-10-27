<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'batch_number' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'expiry_date' => ['nullable', 'date', 'after:today'],
        ];
    }
}
