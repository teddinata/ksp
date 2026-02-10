<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Models\User;
use App\Models\Loan;

class MemberResignationRequest extends FormRequest
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
        $user = auth()->user();
        
        // If user is admin/manager, user_id is required
        // If user is member (anggota), user_id is optional (will use auth user)
        return [
            'user_id' => $user && $user->isMember() ? 'nullable|exists:users,id' : 'required|exists:users,id',
            'reason' => 'required|string|min:10|max:500',
            'resignation_date' => 'nullable|date|after_or_equal:today|before_or_equal:' . now()->addDays(90)->toDateString(),
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
            'reason.required' => 'Alasan keluar harus diisi',
            'reason.min' => 'Alasan keluar minimal 10 karakter',
            'reason.max' => 'Alasan keluar maksimal 500 karakter',
            'resignation_date.date' => 'Format tanggal tidak valid',
            'resignation_date.after_or_equal' => 'Tanggal pengajuan tidak boleh di masa lalu',
            'resignation_date.before_or_equal' => 'Tanggal pengajuan maksimal 90 hari ke depan',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            if ($this->user_id) {
                $user = User::find($this->user_id);
                
                if (!$user) {
                    return;
                }
                
                // 1. Must be active
                if ($user->status !== 'active') {
                    $validator->errors()->add(
                        'user_id',
                        'Member tidak aktif. Status: ' . $user->status
                    );
                }
                
                // 2. CRITICAL: No active loans allowed
                $activeLoans = Loan::where('user_id', $user->id)
                    ->whereIn('status', ['disbursed', 'active'])
                    ->get();
                
                if ($activeLoans->count() > 0) {
                    $loanNumbers = $activeLoans->pluck('loan_number')->implode(', ');
                    $validator->errors()->add(
                        'user_id',
                        "Tidak dapat mengajukan keluar. Anda masih memiliki {$activeLoans->count()} pinjaman aktif: {$loanNumbers}. Harap lunasi terlebih dahulu."
                    );
                }
                
                // 3. Check if already has pending resignation
                if ($user->activeResignation) {
                    $validator->errors()->add(
                        'user_id',
                        'Anda sudah memiliki pengajuan keluar yang masih dalam proses'
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

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        $user = auth()->user();
        
        $mergeData = [];
        
        // If user is member (anggota) and user_id not provided, use authenticated user
        if ($user && $user->isMember() && !$this->user_id) {
            $mergeData['user_id'] = $user->id;
        }
        
        // Set default resignation_date to today if not provided
        if (!$this->resignation_date) {
            $mergeData['resignation_date'] = now()->toDateString();
        }
        
        if (!empty($mergeData)) {
            $this->merge($mergeData);
        }
    }
}