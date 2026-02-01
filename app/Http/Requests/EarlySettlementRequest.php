<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\Loan;

class EarlySettlementRequest extends FormRequest
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
            'settlement_notes' => 'nullable|string|max:500',
            'confirm_amount' => 'required|boolean|accepted',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'settlement_notes.max' => 'Catatan pelunasan maksimal 500 karakter',
            'confirm_amount.required' => 'Konfirmasi nominal pelunasan harus dicentang',
            'confirm_amount.accepted' => 'Anda harus mengkonfirmasi bahwa nominal pelunasan sudah benar',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // Get loan from route parameter
            $loanId = $this->route('id') ?? $this->route('loan');
            
            if (!$loanId) {
                $validator->errors()->add('loan', 'ID pinjaman tidak ditemukan');
                return;
            }
            
            $loan = Loan::find($loanId);
            
            if (!$loan) {
                $validator->errors()->add('loan', 'Pinjaman tidak ditemukan');
                return;
            }
            
            // 1. Loan must be active/disbursed
            if (!in_array($loan->status, ['disbursed', 'active'])) {
                $validator->errors()->add(
                    'loan',
                    "Hanya pinjaman dengan status aktif yang dapat dilunasi. Status saat ini: {$loan->status}"
                );
            }
            
            // 2. Must have remaining principal
            if ($loan->remaining_principal <= 0) {
                $validator->errors()->add(
                    'loan',
                    'Pinjaman sudah lunas. Sisa pokok: Rp 0'
                );
            }
            
            // 3. Cannot settle if already in early settlement
            if ($loan->is_early_settlement) {
                $validator->errors()->add(
                    'loan',
                    'Pinjaman ini sudah pernah dilunasi dipercepat'
                );
            }
            
            // 4. Must have at least one paid installment (optional business rule)
            $paidInstallments = $loan->installments()
                ->whereIn('status', ['paid', 'auto_paid'])
                ->count();
            
            if ($paidInstallments === 0) {
                $validator->errors()->add(
                    'loan',
                    'Pelunasan dipercepat hanya dapat dilakukan setelah minimal 1 kali cicilan dibayar'
                );
            }
            
            // 5. Validate member is still active
            if ($loan->user && $loan->user->status !== 'active') {
                $validator->errors()->add(
                    'loan',
                    'Member tidak aktif. Tidak dapat memproses pelunasan'
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
                'message' => 'Anda tidak memiliki akses. Hanya admin dan manager yang dapat memproses pelunasan dipercepat.',
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