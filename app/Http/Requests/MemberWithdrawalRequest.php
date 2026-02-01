<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\CashAccount;

class MemberWithdrawalRequest extends FormRequest
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
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'payment_method' => 'required|in:cash,transfer,check',
            
            // Transfer details (required if payment_method = transfer)
            'bank_name' => 'required_if:payment_method,transfer|string|max:100',
            'account_number' => 'required_if:payment_method,transfer|string|max:50',
            'account_holder_name' => 'required_if:payment_method,transfer|string|max:200',
            'transfer_reference' => 'nullable|string|max:100',
            
            // Check details (required if payment_method = check)
            'check_number' => 'required_if:payment_method,check|string|max:50',
            'check_date' => 'required_if:payment_method,check|date|after_or_equal:today',
            
            // Notes
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'cash_account_id.required' => 'Kas untuk pencairan harus dipilih',
            'cash_account_id.exists' => 'Kas tidak ditemukan',
            'payment_method.required' => 'Metode pembayaran harus dipilih',
            'payment_method.in' => 'Metode pembayaran tidak valid',
            
            // Transfer
            'bank_name.required_if' => 'Nama bank harus diisi untuk metode transfer',
            'account_number.required_if' => 'Nomor rekening harus diisi untuk metode transfer',
            'account_holder_name.required_if' => 'Nama pemilik rekening harus diisi untuk metode transfer',
            
            // Check
            'check_number.required_if' => 'Nomor cek harus diisi untuk metode cek',
            'check_date.required_if' => 'Tanggal cek harus diisi untuk metode cek',
            'check_date.after_or_equal' => 'Tanggal cek tidak boleh mundur',
            
            'notes.max' => 'Catatan maksimal 1000 karakter',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // 1. Cash account must be active
            if ($this->cash_account_id) {
                $cashAccount = CashAccount::find($this->cash_account_id);
                
                if ($cashAccount && !$cashAccount->is_active) {
                    $validator->errors()->add(
                        'cash_account_id',
                        "Kas ({$cashAccount->name}) tidak aktif"
                    );
                }
            }
            
            // 2. Validate sufficient balance
            $resignationId = $this->route('resignation') ?? $this->route('id');
            
            if ($resignationId && $this->cash_account_id) {
                $resignation = \App\Models\MemberResignation::find($resignationId);
                $cashAccount = CashAccount::find($this->cash_account_id);
                
                if ($resignation && $cashAccount) {
                    $totalWithdrawal = $resignation->total_savings;
                    
                    if ($cashAccount->current_balance < $totalWithdrawal) {
                        $validator->errors()->add(
                            'cash_account_id',
                            "Saldo kas tidak mencukupi. Dibutuhkan: Rp " . 
                            number_format($totalWithdrawal, 0, ',', '.') .
                            ", Tersedia: Rp " . 
                            number_format($cashAccount->current_balance, 0, ',', '.')
                        );
                    }
                }
            }
            
            // 3. Validate account number format for transfer
            if ($this->payment_method === 'transfer' && $this->account_number) {
                $cleanNumber = preg_replace('/[^0-9]/', '', $this->account_number);
                
                if (strlen($cleanNumber) < 8) {
                    $validator->errors()->add(
                        'account_number',
                        'Nomor rekening minimal 8 digit'
                    );
                }
                
                if (strlen($cleanNumber) > 20) {
                    $validator->errors()->add(
                        'account_number',
                        'Nomor rekening maksimal 20 digit'
                    );
                }
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
                'message' => 'Anda tidak memiliki akses. Hanya admin dan manager yang dapat memproses pencairan.',
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
}