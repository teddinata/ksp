<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class AssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_name' => 'required|string|max:255',
            'category' => 'required|in:land,building,vehicle,equipment,inventory',
            'acquisition_cost' => 'required|numeric|min:0',
            'acquisition_date' => 'required|date',
            'useful_life_months' => 'required|integer|min:0',
            'residual_value' => 'nullable|numeric|min:0',
            'location' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,damaged,sold,disposed',
            'notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'asset_name.required' => 'Asset name is required',
            'category.required' => 'Category is required',
            'category.in' => 'Invalid category',
            'acquisition_cost.required' => 'Acquisition cost is required',
            'acquisition_cost.min' => 'Acquisition cost must be at least 0',
            'acquisition_date.required' => 'Acquisition date is required',
            'useful_life_months.required' => 'Useful life is required',
            'useful_life_months.min' => 'Useful life must be at least 0',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $residualValue = $this->input('residual_value', 0);
            $acquisitionCost = $this->input('acquisition_cost', 0);

            if ($residualValue >= $acquisitionCost) {
                $validator->errors()->add('residual_value', 'Nilai residu harus lebih kecil dari biaya perolehan.');
            }
        });
    }

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