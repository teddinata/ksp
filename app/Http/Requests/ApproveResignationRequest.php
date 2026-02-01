<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ApproveResignationRequest extends FormRequest
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
            'action' => 'required|in:approve,reject',
            'rejection_reason' => 'required_if:action,reject|string|min:10|max:500',
            'admin_notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'action.required' => 'Aksi harus dipilih',
            'action.in' => 'Aksi harus approve atau reject',
            'rejection_reason.required_if' => 'Alasan penolakan harus diisi ketika menolak',
            'rejection_reason.min' => 'Alasan penolakan minimal 10 karakter',
            'rejection_reason.max' => 'Alasan penolakan maksimal 500 karakter',
            'admin_notes.max' => 'Catatan admin maksimal 1000 karakter',
        ];
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization()
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses. Hanya admin dan manager yang dapat memproses pengajuan keluar.',
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