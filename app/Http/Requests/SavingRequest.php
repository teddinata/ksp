<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use App\Models\Saving;

class SavingRequest extends FormRequest
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
            'user_id' => 'required|exists:users,id',
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'savings_type' => 'required|in:principal,mandatory,voluntary,holiday',
            'amount' => 'required|numeric|min:10000', // Min 10k
            'transaction_date' => 'required|date',
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
            'user_id.required' => 'User ID is required',
            'user_id.exists' => 'User not found',
            'cash_account_id.required' => 'Cash account is required',
            'cash_account_id.exists' => 'Cash account not found',
            'savings_type.required' => 'Savings type is required',
            'savings_type.in' => 'Invalid savings type. Must be: principal, mandatory, voluntary, or holiday',
            'amount.required' => 'Amount is required',
            'amount.numeric' => 'Amount must be a number',
            'amount.min' => 'Minimum amount is Rp 10,000',
            'transaction_date.required' => 'Transaction date is required',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Check if principal savings already exists
            if ($this->savings_type === 'principal' && $this->user_id) {
                if (Saving::hasPrincipal($this->user_id)) {
                    $validator->errors()->add(
                        'savings_type',
                        'User already has principal savings. Only one principal saving allowed per member.'
                    );
                }
            }

            // Validate minimum amount for principal
            if ($this->savings_type === 'principal' && $this->amount) {
                if ($this->amount < 100000) { // Min 100k for principal
                    $validator->errors()->add(
                        'amount',
                        'Minimum amount for principal savings is Rp 100,000'
                    );
                }
            }

            // Validate user is active
            if ($this->user_id) {
                $user = \App\Models\User::find($this->user_id);
                if ($user && !$user->isActive()) {
                    $validator->errors()->add(
                        'user_id',
                        'User account is inactive. Cannot create savings.'
                    );
                }
            }

            // Validate cash account is active
            if ($this->cash_account_id) {
                $cashAccount = \App\Models\CashAccount::find($this->cash_account_id);
                if ($cashAccount && !$cashAccount->is_active) {
                    $validator->errors()->add(
                        'cash_account_id',
                        'Cash account is inactive. Cannot process transaction.'
                    );
                }
            }
        });
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