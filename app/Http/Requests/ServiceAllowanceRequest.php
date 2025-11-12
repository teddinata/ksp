<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ServiceAllowanceRequest extends FormRequest
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
            'period_month' => 'required|integer|min:1|max:12',
            'period_year' => 'required|integer|min:2020|max:2100',
            'base_amount' => 'required|numeric|min:0',
            'savings_rate' => 'required|numeric|min:0|max:100',
            'loan_rate' => 'required|numeric|min:0|max:100',
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
            'period_month.required' => 'Period month is required',
            'period_month.min' => 'Period month must be between 1 and 12',
            'period_month.max' => 'Period month must be between 1 and 12',
            'period_year.required' => 'Period year is required',
            'base_amount.required' => 'Base amount is required',
            'base_amount.min' => 'Base amount must be at least 0',
            'savings_rate.required' => 'Savings rate is required',
            'savings_rate.max' => 'Savings rate cannot exceed 100%',
            'loan_rate.required' => 'Loan rate is required',
            'loan_rate.max' => 'Loan rate cannot exceed 100%',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Check if period is not in the future
            if ($this->period_year && $this->period_month) {
                $periodDate = \Carbon\Carbon::create($this->period_year, $this->period_month, 1);
                
                if ($periodDate->isFuture()) {
                    $validator->errors()->add(
                        'period_month',
                        'Cannot distribute service allowance for future periods'
                    );
                }
            }

            // Check if already distributed for this period
            if ($this->period_year && $this->period_month) {
                $existing = \App\Models\ServiceAllowance::where('period_month', $this->period_month)
                    ->where('period_year', $this->period_year)
                    ->count();

                if ($existing > 0) {
                    $validator->errors()->add(
                        'period_month',
                        'Service allowance for this period has already been distributed'
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