<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoanRequest extends FormRequest
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
            'principal_amount' => 'required|numeric|min:1000000|max:100000000', // 1jt - 100jt
            'tenure_months' => 'required|integer|min:6|max:60', // 6 months - 5 years
            'application_date' => 'required|date',
            'loan_purpose' => 'required|string|min:10',
            'document_path' => 'nullable|string',
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
            'principal_amount.required' => 'Loan amount is required',
            'principal_amount.min' => 'Minimum loan amount is Rp 1,000,000',
            'principal_amount.max' => 'Maximum loan amount is Rp 100,000,000',
            'tenure_months.required' => 'Loan tenure is required',
            'tenure_months.min' => 'Minimum tenure is 6 months',
            'tenure_months.max' => 'Maximum tenure is 60 months (5 years)',
            'loan_purpose.required' => 'Loan purpose is required',
            'loan_purpose.min' => 'Loan purpose must be at least 10 characters',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Check if user has active loan
            if ($this->user_id) {
                $activeLoans = \App\Models\Loan::where('user_id', $this->user_id)
                    ->whereIn('status', ['approved', 'disbursed', 'active'])
                    ->count();

                if ($activeLoans > 0) {
                    $validator->errors()->add(
                        'user_id',
                        'User already has an active loan. Only one active loan allowed per member.'
                    );
                }
            }

            // Validate user is active
            if ($this->user_id) {
                $user = \App\Models\User::find($this->user_id);
                if ($user && !$user->isActive()) {
                    $validator->errors()->add(
                        'user_id',
                        'User account is inactive. Cannot apply for loan.'
                    );
                }
            }

            // Validate cash account is active
            if ($this->cash_account_id) {
                $cashAccount = \App\Models\CashAccount::find($this->cash_account_id);
                if ($cashAccount && !$cashAccount->is_active) {
                    $validator->errors()->add(
                        'cash_account_id',
                        'Cash account is inactive. Cannot process loan.'
                    );
                }

                // Check if cash account has sufficient balance
                if ($cashAccount && $this->principal_amount) {
                    if ($cashAccount->current_balance < $this->principal_amount) {
                        $validator->errors()->add(
                            'principal_amount',
                            'Insufficient balance in cash account. Available: Rp ' . number_format($cashAccount->current_balance, 0, ',', '.')
                        );
                    }
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