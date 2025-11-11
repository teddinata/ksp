<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class InterestRateRequest extends FormRequest
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
            'transaction_type' => 'required|in:savings,loans',
            'rate_percentage' => 'required|numeric|min:0|max:100',
            'effective_date' => 'required|date',
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
            'transaction_type.required' => 'Transaction type is required',
            'transaction_type.in' => 'Invalid transaction type. Must be: savings or loans',
            'rate_percentage.required' => 'Interest rate percentage is required',
            'rate_percentage.min' => 'Interest rate cannot be negative',
            'rate_percentage.max' => 'Interest rate cannot exceed 100%',
            'effective_date.required' => 'Effective date is required',
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