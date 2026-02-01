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
            
            // Support both old enum and new saving_type_id
            'savings_type' => 'nullable|in:principal,mandatory,voluntary,holiday',
            'saving_type_id' => 'nullable|exists:saving_types,id',
            
            'amount' => 'required|numeric|min:10000',
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
            'savings_type.in' => 'Invalid savings type. Must be: principal, mandatory, voluntary, or holiday',
            'saving_type_id.exists' => 'Jenis simpanan tidak ditemukan',
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
            // Ensure either savings_type or saving_type_id is provided
            if (!$this->savings_type && !$this->saving_type_id) {
                $validator->errors()->add(
                    'savings_type',
                    'Either savings_type or saving_type_id must be provided'
                );
                return;
            }
            
            // Get saving type (from old enum or new model)
            $savingType = null;
            
            if ($this->saving_type_id) {
                $savingType = \App\Models\SavingType::find($this->saving_type_id);
                
                if ($savingType && !$savingType->is_active) {
                    $validator->errors()->add(
                        'saving_type_id',
                        'Jenis simpanan tidak aktif'
                    );
                }
            } else {
                // Use old enum system - map to saving_type_id
                $typeMapping = [
                    'principal' => 'POKOK',
                    'mandatory' => 'WAJIB',
                    'voluntary' => 'SUKARELA',
                    'holiday' => 'HARIRAYA',
                ];
                
                if (isset($typeMapping[$this->savings_type])) {
                    $savingType = \App\Models\SavingType::where('code', $typeMapping[$this->savings_type])->first();
                }
            }
            
            // Validate amount based on saving type rules
            if ($savingType && $this->amount) {
                $validation = $savingType->validateAmount($this->amount);
                
                if (!$validation['valid']) {
                    foreach ($validation['errors'] as $error) {
                        $validator->errors()->add('amount', $error);
                    }
                }
            }
            
            // Check if principal savings already exists (OLD LOGIC - keep for compatibility)
            if ($this->savings_type === 'principal' && $this->user_id) {
                if (Saving::hasPrincipal($this->user_id)) {
                    $validator->errors()->add(
                        'savings_type',
                        'User already has principal savings. Only one principal saving allowed per member.'
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