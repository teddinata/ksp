<?php

namespace App\Http\Requests;

use App\Models\AccountingPeriod;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class AccountingPeriodRequest extends FormRequest
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
            'period_name' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
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
            'start_date.required' => 'Start date is required',
            'start_date.date' => 'Start date must be a valid date',
            'end_date.required' => 'End date is required',
            'end_date.date' => 'End date must be a valid date',
            'end_date.after_or_equal' => 'End date must be equal to or after start date',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Check for date overlap with existing periods
            if ($this->start_date && $this->end_date) {
                $periodId = $this->route('id'); // For update
                
                $startDate = Carbon::parse($this->start_date);
                $endDate = Carbon::parse($this->end_date);

                if (AccountingPeriod::hasOverlap($startDate, $endDate, $periodId)) {
                    $validator->errors()->add(
                        'dates', 
                        'Period dates overlap with an existing period'
                    );
                }
            }

            // Validate period duration (not too long)
            if ($this->start_date && $this->end_date) {
                $startDate = Carbon::parse($this->start_date);
                $endDate = Carbon::parse($this->end_date);
                $days = $startDate->diffInDays($endDate) + 1;

                if ($days > 366) {
                    $validator->errors()->add(
                        'dates', 
                        'Period duration cannot exceed 366 days (1 year)'
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

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        // Auto-generate period name if not provided
        if (!$this->period_name && $this->start_date && $this->end_date) {
            $startDate = Carbon::parse($this->start_date);
            $endDate = Carbon::parse($this->end_date);
            
            $this->merge([
                'period_name' => AccountingPeriod::generatePeriodName($startDate, $endDate)
            ]);
        }
    }
}