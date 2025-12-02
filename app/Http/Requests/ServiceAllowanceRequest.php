<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\User;
use App\Models\ServiceAllowance;

class ServiceAllowanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Only admin and manager can create service allowance
        $user = auth()->user();
        return $user && ($user->isAdmin() || $user->isManager());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Member selection
            'user_id' => 'required|exists:users,id',
            
            // Period
            'period_month' => 'required|integer|min:1|max:12',
            'period_year' => 'required|integer|min:2020|max:2100',
            
            // Amount (manual input dari RS)
            'received_amount' => 'required|numeric|min:0',
            
            // Optional notes
            'notes' => 'nullable|string|max:1000',
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
            // User
            'user_id.required' => 'Member harus dipilih',
            'user_id.exists' => 'Member tidak ditemukan',
            
            // Period
            'period_month.required' => 'Bulan periode harus diisi',
            'period_month.min' => 'Bulan periode harus antara 1-12',
            'period_month.max' => 'Bulan periode harus antara 1-12',
            'period_year.required' => 'Tahun periode harus diisi',
            'period_year.min' => 'Tahun periode minimal 2020',
            'period_year.max' => 'Tahun periode maksimal 2100',
            
            // Amount
            'received_amount.required' => 'Nominal jasa pelayanan harus diisi',
            'received_amount.numeric' => 'Nominal jasa pelayanan harus berupa angka',
            'received_amount.min' => 'Nominal jasa pelayanan minimal 0',
            
            // Notes
            'notes.max' => 'Catatan maksimal 1000 karakter',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // 1. Check if user is actually a member
            if ($this->user_id) {
                $user = User::find($this->user_id);
                
                if ($user && !$user->isMember()) {
                    $validator->errors()->add(
                        'user_id',
                        'User yang dipilih bukan anggota koperasi'
                    );
                }
                
                // Check if member is active
                if ($user && $user->status !== 'active') {
                    $validator->errors()->add(
                        'user_id',
                        'Member tidak aktif. Hanya member aktif yang bisa menerima jasa pelayanan'
                    );
                }
            }
            
            // 2. Check if period is not too far in the future (max 1 month ahead)
            if ($this->period_year && $this->period_month) {
                $periodDate = \Carbon\Carbon::create($this->period_year, $this->period_month, 1);
                $now = \Carbon\Carbon::now();
                
                if ($periodDate->diffInMonths($now, false) > 1) {
                    $validator->errors()->add(
                        'period_month',
                        'Tidak dapat input jasa pelayanan untuk periode lebih dari 1 bulan ke depan'
                    );
                }
            }
            
            // 3. Check if already exists for this member and period
            if ($this->user_id && $this->period_year && $this->period_month) {
                $existing = ServiceAllowance::where('user_id', $this->user_id)
                    ->where('period_month', $this->period_month)
                    ->where('period_year', $this->period_year)
                    ->first();

                if ($existing) {
                    $periodDisplay = \Carbon\Carbon::create($this->period_year, $this->period_month, 1)
                        ->format('F Y');
                    
                    $validator->errors()->add(
                        'user_id',
                        "Member ini sudah menerima jasa pelayanan untuk periode {$periodDisplay}"
                    );
                }
            }
            
            // 4. Validate amount is reasonable (optional warning, not blocking)
            if ($this->received_amount) {
                // Warning jika terlalu besar (> 10 juta)
                if ($this->received_amount > 10000000) {
                    // This is just a warning, not blocking
                    // Could add to messages but not errors
                    \Log::warning('Large service allowance amount', [
                        'user_id' => $this->user_id,
                        'amount' => $this->received_amount,
                        'period' => $this->period_month . '/' . $this->period_year,
                    ]);
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

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization()
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melakukan aksi ini. Hanya admin dan manager yang dapat menginput jasa pelayanan.',
            ], 403)
        );
    }
}