<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\User;
use App\Models\SalaryDeduction;

class SalaryDeductionRequest extends FormRequest
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
            'user_id' => 'required|exists:users,id',
            'period_month' => 'required|integer|min:1|max:12',
            'period_year' => 'required|integer|min:2020|max:2100',
            'gross_salary' => 'required|numeric|min:0',
            'savings_deduction' => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Member harus dipilih',
            'user_id.exists' => 'Member tidak ditemukan',
            'period_month.required' => 'Bulan periode harus diisi',
            'period_month.min' => 'Bulan periode harus antara 1-12',
            'period_month.max' => 'Bulan periode harus antara 1-12',
            'period_year.required' => 'Tahun periode harus diisi',
            'period_year.min' => 'Tahun periode minimal 2020',
            'period_year.max' => 'Tahun periode maksimal 2100',
            'gross_salary.required' => 'Gaji kotor harus diisi',
            'gross_salary.numeric' => 'Gaji kotor harus berupa angka',
            'gross_salary.min' => 'Gaji kotor tidak boleh negatif',
            'savings_deduction.numeric' => 'Potongan simpanan harus berupa angka',
            'savings_deduction.min' => 'Potongan simpanan tidak boleh negatif',
            'other_deductions.numeric' => 'Potongan lainnya harus berupa angka',
            'other_deductions.min' => 'Potongan lainnya tidak boleh negatif',
            'notes.max' => 'Catatan maksimal 1000 karakter',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // 1. User must be a member
            if ($this->user_id) {
                $user = User::find($this->user_id);
                
                if ($user && !$user->isMember()) {
                    $validator->errors()->add(
                        'user_id',
                        'User bukan anggota. Hanya anggota yang dapat diproses potongan gaji'
                    );
                }
                
                if ($user && $user->status !== 'active') {
                    $validator->errors()->add(
                        'user_id',
                        'Member tidak aktif. Status: ' . $user->status
                    );
                }
            }
            
            // 2. Check if already exists for this period
            if ($this->user_id && $this->period_month && $this->period_year) {
                $existing = SalaryDeduction::where('user_id', $this->user_id)
                    ->where('period_month', $this->period_month)
                    ->where('period_year', $this->period_year)
                    ->first();
                
                if ($existing) {
                    $periodDisplay = \Carbon\Carbon::create($this->period_year, $this->period_month, 1)
                        ->format('F Y');
                    
                    $validator->errors()->add(
                        'period_month',
                        "Potongan gaji untuk periode {$periodDisplay} sudah ada"
                    );
                }
            }
            
            // 3. Period should not be too far in the future
            if ($this->period_year && $this->period_month) {
                $periodDate = \Carbon\Carbon::create($this->period_year, $this->period_month, 1);
                $now = \Carbon\Carbon::now();
                
                if ($periodDate->diffInMonths($now, false) > 1) {
                    $validator->errors()->add(
                        'period_month',
                        'Tidak dapat memproses potongan untuk periode lebih dari 1 bulan ke depan'
                    );
                }
            }
            
            // 4. Total deductions should not exceed gross salary
            if ($this->gross_salary) {
                $savingsDeduction = $this->savings_deduction ?? 0;
                $otherDeductions = $this->other_deductions ?? 0;
                
                // Note: loan deduction will be calculated automatically
                // We'll validate total after calculation in the controller
                
                if (($savingsDeduction + $otherDeductions) > $this->gross_salary) {
                    $validator->errors()->add(
                        'gross_salary',
                        'Total potongan (simpanan + lainnya) melebihi gaji kotor'
                    );
                }
            }
            
            // 5. Gross salary should be reasonable
            if ($this->gross_salary) {
                if ($this->gross_salary < 1000000) { // Min UMR-like
                    $validator->errors()->add(
                        'gross_salary',
                        'Gaji kotor terlalu kecil. Minimal Rp 1.000.000'
                    );
                }
                
                if ($this->gross_salary > 1000000000) { // Max 1 miliar
                    $validator->errors()->add(
                        'gross_salary',
                        'Gaji kotor terlalu besar. Maksimal Rp 1.000.000.000'
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
                'message' => 'Anda tidak memiliki akses. Hanya admin dan manager yang dapat memproses potongan gaji.',
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
        // Set default period to current month if not provided
        if (!$this->period_month || !$this->period_year) {
            $now = now();
            $defaults = [];
            
            if (!$this->period_month) {
                $defaults['period_month'] = $now->month;
            }
            
            if (!$this->period_year) {
                $defaults['period_year'] = $now->year;
            }
            
            if (count($defaults) > 0) {
                $this->merge($defaults);
            }
        }
        
        // Set default values for optional deductions
        if (!$this->has('savings_deduction')) {
            $this->merge(['savings_deduction' => 0]);
        }
        
        if (!$this->has('other_deductions')) {
            $this->merge(['other_deductions' => 0]);
        }
    }
}