<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ChartOfAccountRequest extends FormRequest
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
        $accountId = $this->route('id'); // For update

        return [
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('chart_of_accounts', 'code')->ignore($accountId)
            ],
            'name' => 'required|string|max:255',
            'category' => 'required|in:assets,liabilities,equity,revenue,expenses',
            'account_type' => 'nullable|string|max:50',
            'is_debit' => 'required|boolean',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string',
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
            'code.required' => 'Account code is required',
            'code.unique' => 'Account code already exists',
            'name.required' => 'Account name is required',
            'category.required' => 'Category is required',
            'category.in' => 'Invalid category. Must be: assets, liabilities, equity, revenue, or expenses',
            'is_debit.required' => 'Balance type is required',
            'is_debit.boolean' => 'Balance type must be true (Debit) or false (Credit)',
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
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