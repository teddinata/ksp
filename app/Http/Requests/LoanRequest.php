<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\User;
use App\Models\CashAccount;
use App\Models\Loan;

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
        $rules = [
            'user_id' => 'required|exists:users,id',
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'principal_amount' => 'required|numeric|min:100000',
            'tenure_months' => 'required|integer|min:6|max:60',
            'application_date' => 'required|date',
            'loan_purpose' => 'required|string|max:500',
            'document_path' => 'nullable|string',
        ];

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Member harus dipilih',
            'user_id.exists' => 'Member tidak ditemukan',
            'cash_account_id.required' => 'Kas pinjaman harus dipilih',
            'cash_account_id.exists' => 'Kas tidak ditemukan',
            'principal_amount.required' => 'Jumlah pinjaman harus diisi',
            'principal_amount.numeric' => 'Jumlah pinjaman harus berupa angka',
            'principal_amount.min' => 'Jumlah pinjaman minimal Rp 100.000',
            'tenure_months.required' => 'Jangka waktu harus diisi',
            'tenure_months.integer' => 'Jangka waktu harus berupa angka',
            'tenure_months.min' => 'Jangka waktu minimal 6 bulan',
            'tenure_months.max' => 'Jangka waktu maksimal 60 bulan',
            'application_date.required' => 'Tanggal pengajuan harus diisi',
            'application_date.date' => 'Format tanggal pengajuan tidak valid',
            'loan_purpose.required' => 'Tujuan pinjaman harus diisi',
            'loan_purpose.max' => 'Tujuan pinjaman maksimal 500 karakter',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // ==================== VALIDATION 1: User is Member ====================
            if ($this->user_id) {
                $user = User::find($this->user_id);
                
                if ($user && !$user->isMember()) {
                    $validator->errors()->add(
                        'user_id',
                        'Hanya member yang dapat mengajukan pinjaman'
                    );
                }
                
                if ($user && $user->status !== 'active') {
                    $validator->errors()->add(
                        'user_id',
                        'Member tidak aktif. Hanya member aktif yang dapat mengajukan pinjaman'
                    );
                }
            }
            
            // ==================== VALIDATION 2: Cash Account is Active ====================
            if ($this->cash_account_id) {
                $cashAccount = CashAccount::find($this->cash_account_id);
                
                if ($cashAccount && !$cashAccount->is_active) {
                    $validator->errors()->add(
                        'cash_account_id',
                        'Kas tidak aktif. Tidak dapat menerima pengajuan pinjaman'
                    );
                }
            }
            
            // ==================== VALIDATION 3: Loan Limit Check (CRITICAL!) ====================
            if ($this->user_id && $this->cash_account_id) {
                $user = User::find($this->user_id);
                $cashAccount = CashAccount::find($this->cash_account_id);
                
                if ($user && $cashAccount) {
                    // Use trait method to check eligibility
                    $check = $user->canApplyForLoan($this->cash_account_id);
                    
                    if (!$check['can_apply']) {
                        $validator->errors()->add(
                            'cash_account_id',
                            $check['reason']
                        );
                    }
                }
            }
            
            // ==================== VALIDATION 4: Application Date Not Future ====================
            if ($this->application_date) {
                $applicationDate = \Carbon\Carbon::parse($this->application_date);
                
                if ($applicationDate->isFuture()) {
                    $validator->errors()->add(
                        'application_date',
                        'Tanggal pengajuan tidak boleh lebih dari hari ini'
                    );
                }
            }
            
            // ==================== VALIDATION 5: Principal Amount vs Tenure ====================
            if ($this->principal_amount && $this->tenure_months) {
                // Validate reasonable installment amount (min Rp 50,000 per month)
                $estimatedInstallment = $this->principal_amount / $this->tenure_months;
                
                if ($estimatedInstallment < 50000) {
                    $validator->errors()->add(
                        'principal_amount',
                        'Kombinasi jumlah pinjaman dan jangka waktu tidak valid. Cicilan per bulan minimal Rp 50.000'
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
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422)
        );
    }
}