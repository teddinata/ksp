<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class JournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'journal_type' => 'required|in:general,special,adjusting,closing,reversing',
            'description' => 'required|string|max:500',
            'transaction_date' => 'required|date',
            'accounting_period_id' => 'nullable|exists:accounting_periods,id',
            'details' => 'required|array|min:2',
            'details.*.chart_of_account_id' => 'required|exists:chart_of_accounts,id',
            'details.*.debit' => 'required|numeric|min:0',
            'details.*.credit' => 'required|numeric|min:0',
            'details.*.description' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'journal_type.required' => 'Journal type is required',
            'journal_type.in' => 'Invalid journal type',
            'description.required' => 'Description is required',
            'transaction_date.required' => 'Transaction date is required',
            'details.required' => 'Journal details are required',
            'details.min' => 'At least 2 journal details required (debit & credit)',
            'details.*.chart_of_account_id.required' => 'Account is required for each detail',
            'details.*.chart_of_account_id.exists' => 'Invalid account',
            'details.*.debit.required' => 'Debit amount is required',
            'details.*.credit.required' => 'Credit amount is required',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Validate balance
            $details = $this->input('details', []);
            $totalDebit = array_sum(array_column($details, 'debit'));
            $totalCredit = array_sum(array_column($details, 'credit'));

            if ($totalDebit != $totalCredit) {
                $validator->errors()->add('balance', 'Jurnal is not balanced. Total debit harus sama dengan total kredit.');
            }

            // Validate each detail has either debit or credit (not both)
            foreach ($details as $index => $detail) {
                $debit = $detail['debit'] ?? 0;
                $credit = $detail['credit'] ?? 0;

                if ($debit > 0 && $credit > 0) {
                    $validator->errors()->add("details.{$index}", 'Setiap entri tidak boleh memiliki debit dan kredit sekaligus.');
                }

                if ($debit == 0 && $credit == 0) {
                    $validator->errors()->add("details.{$index}", 'Setiap entri harus memiliki debit atau kredit.');
                }
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