<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class SavingTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Authorization handled by middleware, so always return true here.
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
        $savingTypeId = $this->route('id');
        
        return [
            'code' => [
                'required',
                'string',
                'max:20',
                'alpha_dash',
                Rule::unique('saving_types', 'code')->ignore($savingTypeId)
            ],
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'is_mandatory' => 'required|boolean',
            'is_withdrawable' => 'required|boolean',
            'minimum_amount' => 'required|numeric|min:0',
            'maximum_amount' => 'nullable|numeric|min:0',
            'has_interest' => 'required|boolean',
            'default_interest_rate' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'display_order' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Kode jenis simpanan harus diisi',
            'code.unique' => 'Kode jenis simpanan sudah digunakan',
            'code.alpha_dash' => 'Kode hanya boleh berisi huruf, angka, dash, dan underscore',
            'name.required' => 'Nama jenis simpanan harus diisi',
            'is_mandatory.required' => 'Status wajib harus dipilih',
            'is_withdrawable.required' => 'Status dapat ditarik harus dipilih',
            'minimum_amount.required' => 'Nominal minimal harus diisi',
            'minimum_amount.min' => 'Nominal minimal tidak boleh negatif',
            'maximum_amount.min' => 'Nominal maksimal tidak boleh negatif',
            'has_interest.required' => 'Status berbunga harus dipilih',
            'default_interest_rate.min' => 'Bunga tidak boleh negatif',
            'default_interest_rate.max' => 'Bunga tidak boleh lebih dari 100%',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            
            // 1. Maximum must be greater than minimum
            if ($this->maximum_amount && $this->minimum_amount) {
                if ($this->maximum_amount < $this->minimum_amount) {
                    $validator->errors()->add(
                        'maximum_amount',
                        'Nominal maksimal harus lebih besar dari minimal'
                    );
                }
            }
            
            // 2. If has_interest = true, default_interest_rate must be provided
            if ($this->has_interest && !$this->default_interest_rate) {
                $validator->errors()->add(
                    'default_interest_rate',
                    'Bunga default harus diisi jika simpanan berbunga'
                );
            }
            
            // 3. Protect default types from being deleted/deactivated
            if ($this->is_active === false) {
                $savingTypeId = $this->route('id');
                if ($savingTypeId) {
                    $savingType = \App\Models\SavingType::find($savingTypeId);
                    if ($savingType && in_array($savingType->code, ['POKOK', 'WAJIB', 'SUKARELA', 'HARIRAYA'])) {
                        $validator->errors()->add(
                            'is_active',
                            'Jenis simpanan default tidak dapat dinonaktifkan'
                        );
                    }
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
                'message' => 'Anda tidak memiliki akses. Hanya admin yang dapat mengelola jenis simpanan.',
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
        // Auto-uppercase code
        if ($this->code) {
            $this->merge([
                'code' => strtoupper($this->code)
            ]);
        }
        
        // Set default values
        $defaults = [];
        
        if (!$this->has('is_active')) {
            $defaults['is_active'] = true;
        }
        
        if (!$this->has('has_interest')) {
            $defaults['has_interest'] = false;
        }
        
        if (count($defaults) > 0) {
            $this->merge($defaults);
        }
    }
}