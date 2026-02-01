<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\CashAccount;

class CashTransferRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'from_cash_account_id' => 'required|exists:cash_accounts,id',
            'to_cash_account_id' => 'required|exists:cash_accounts,id|different:from_cash_account_id',
            'amount' => 'required|numeric|min:1000',
            'transfer_date' => 'required|date|before_or_equal:today',
            'purpose' => 'required|string|min:5|max:500',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'from_cash_account_id.required' => 'Kas sumber harus dipilih',
            'from_cash_account_id.exists' => 'Kas sumber tidak ditemukan',
            'to_cash_account_id.required' => 'Kas tujuan harus dipilih',
            'to_cash_account_id.exists' => 'Kas tujuan tidak ditemukan',
            'to_cash_account_id.different' => 'Kas tujuan harus berbeda dengan kas sumber',
            'amount.required' => 'Nominal transfer harus diisi',
            'amount.numeric' => 'Nominal transfer harus berupa angka',
            'amount.min' => 'Nominal transfer minimal Rp 1.000',
            'transfer_date.required' => 'Tanggal transfer harus diisi',
            'transfer_date.date' => 'Format tanggal tidak valid',
            'transfer_date.before_or_equal' => 'Tanggal transfer tidak boleh di masa depan',
            'purpose.required' => 'Tujuan transfer harus diisi',
            'purpose.min' => 'Tujuan transfer minimal 5 karakter',
            'purpose.max' => 'Tujuan transfer maksimal 500 karakter',
            'notes.max' => 'Catatan maksimal 1000 karakter',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // 1. Both accounts must be active
            if ($this->from_cash_account_id) {
                $fromAccount = CashAccount::find($this->from_cash_account_id);
                
                if ($fromAccount && !$fromAccount->is_active) {
                    $validator->errors()->add(
                        'from_cash_account_id',
                        "Kas sumber ({$fromAccount->name}) tidak aktif"
                    );
                }
            }
            
            if ($this->to_cash_account_id) {
                $toAccount = CashAccount::find($this->to_cash_account_id);
                
                if ($toAccount && !$toAccount->is_active) {
                    $validator->errors()->add(
                        'to_cash_account_id',
                        "Kas tujuan ({$toAccount->name}) tidak aktif"
                    );
                }
            }
            
            // 2. CRITICAL: Check sufficient balance
            if ($this->from_cash_account_id && $this->amount) {
                $fromAccount = CashAccount::find($this->from_cash_account_id);
                
                if ($fromAccount && $fromAccount->current_balance < $this->amount) {
                    $validator->errors()->add(
                        'amount',
                        "Saldo kas sumber tidak mencukupi. Saldo tersedia: Rp " . 
                        number_format($fromAccount->current_balance, 0, ',', '.') .
                        ", Dibutuhkan: Rp " . number_format($this->amount, 0, ',', '.')
                    );
                }
            }
            
            // 3. Validate amount is reasonable (not too large)
            if ($this->amount && $this->amount > 1000000000) { // 1 miliar
                $validator->errors()->add(
                    'amount',
                    'Nominal transfer terlalu besar. Maksimal Rp 1.000.000.000 per transaksi'
                );
            }
        });
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization()
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses. Hanya admin dan manager yang dapat melakukan transfer kas.',
            ], 403)
        );
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422)
        );
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        // Set transfer_date to today if not provided
        if (!$this->transfer_date) {
            $this->merge([
                'transfer_date' => now()->toDateString()
            ]);
        }
    }
}