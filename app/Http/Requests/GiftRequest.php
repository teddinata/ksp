<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class GiftRequest extends FormRequest
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
            'gift_type' => 'required|in:holiday,achievement,birthday,special_event,loyalty',
            'gift_name' => 'required|string|max:255',
            'gift_value' => 'required|numeric|min:0',
            'distribution_date' => 'required|date',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
            'notes' => 'nullable|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'gift_type.required' => 'Gift type is required',
            'gift_type.in' => 'Invalid gift type',
            'gift_name.required' => 'Gift name is required',
            'gift_value.required' => 'Gift value is required',
            'gift_value.min' => 'Gift value must be at least 0',
            'distribution_date.required' => 'Distribution date is required',
            'user_ids.array' => 'User IDs must be an array',
            'user_ids.*.exists' => 'One or more users not found',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422)
        );
    }
}