<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBatchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'urls' => ['required', 'array', 'min:1', 'max:5'],
            'urls.*' => [
                'required',
                'url',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (strpos(strtolower((string) $value), 'https://') !== 0) {
                        $fail("The {$attribute} must be an HTTPS URL.");
                    }
                },
            ],
        ];
    }
}
