<?php

namespace App\Http\Requests;

use App\Enums\DemandUrgency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDemandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Otorisasi per posko dilakukan di controller lewat policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // ID dari perangkat (UUID) supaya pengiriman ulang dari mode offline tidak menggandakan permintaan.
            'id' => ['nullable', 'uuid'],
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'quantity_requested' => ['required', 'numeric', 'gt:0', 'max:9999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'urgency_level' => ['required', Rule::enum(DemandUrgency::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
