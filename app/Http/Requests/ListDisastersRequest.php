<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListDisastersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'min_magnitude' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'source' => ['nullable', 'in:bmkg,usgs,manual'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }
}
