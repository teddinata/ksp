<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InterestRateRequest;
use App\Models\CashAccount;
use App\Models\InterestRate;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterestRateController extends Controller
{
    use ApiResponse;

    /**
     * Get interest rates for a cash account.
     * 
     * Business Logic:
     * - Shows rate history (newest first)
     * - Can filter by transaction type
     * - Shows who set the rate and when
     *
     * @param Request $request
     * @param int $cashAccountId
     * @return JsonResponse
     */
    public function index(Request $request, int $cashAccountId): JsonResponse
    {
        try {
            $cashAccount = CashAccount::findOrFail($cashAccountId);

            $query = $cashAccount->interestRates()
                ->with('updatedBy:id,full_name,email');

            // Filter by transaction type
            if ($request->has('transaction_type')) {
                if ($request->transaction_type === 'savings') {
                    $query->forSavings();
                } elseif ($request->transaction_type === 'loans') {
                    $query->forLoans();
                }
            }

            // Filter by effective date range
            if ($request->has('from_date')) {
                $query->where('effective_date', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->where('effective_date', '<=', $request->to_date);
            }

            // Sort by effective date (newest first)
            $rates = $query->orderBy('effective_date', 'desc')->get();

            return $this->successResponse(
                [
                    'cash_account' => $cashAccount->only(['id', 'code', 'name']),
                    'rates' => $rates,
                    'current_savings_rate' => $cashAccount->currentSavingsRate(),
                    'current_loan_rate' => $cashAccount->currentLoanRate(),
                ],
                'Interest rates retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Cash account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve interest rates: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Set a new interest rate.
     * 
     * Business Logic:
     * - Only Admin and assigned Manager can set rates
     * - Creates new rate record (does not update existing)
     * - Rate becomes effective from effective_date
     * - System auto-picks latest rate based on effective_date
     *
     * @param InterestRateRequest $request
     * @param int $cashAccountId
     * @return JsonResponse
     */
    public function store(InterestRateRequest $request, int $cashAccountId): JsonResponse
    {
        try {
            $user = auth()->user();
            $cashAccount = CashAccount::findOrFail($cashAccountId);

            // Access Control: Manager must be assigned to this kas
            if ($user->isManager() && !$cashAccount->isManagedBy($user->id)) {
                return $this->errorResponse(
                    'You do not have permission to set rates for this cash account',
                    403
                );
            }

            // Create new rate
            $rate = InterestRate::create([
                'cash_account_id' => $cashAccountId,
                'transaction_type' => $request->transaction_type,
                'rate_percentage' => $request->rate_percentage,
                'effective_date' => $request->effective_date,
                'updated_by' => $user->id,
            ]);

            $rate->load('updatedBy:id,full_name,email');

            return $this->successResponse(
                [
                    'rate' => $rate,
                    'cash_account' => $cashAccount->only(['id', 'code', 'name']),
                ],
                'Interest rate set successfully',
                201
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Cash account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to set interest rate: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update an existing interest rate.
     * 
     * Business Logic:
     * - Only Admin can update
     * - Can only update future rates (effective_date >= today)
     * - Cannot update rates that are already effective
     *
     * @param InterestRateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(InterestRateRequest $request, int $id): JsonResponse
    {
        try {
            $rate = InterestRate::with('cashAccount')->findOrFail($id);

            // Check if rate is already effective
            if ($rate->effective_date < now()->toDateString()) {
                return $this->errorResponse(
                    'Cannot update interest rate that is already effective',
                    400
                );
            }

            // Access Control: Manager must be assigned to this kas
            $user = auth()->user();
            if ($user->isManager() && !$rate->cashAccount->isManagedBy($user->id)) {
                return $this->errorResponse(
                    'You do not have permission to update rates for this cash account',
                    403
                );
            }

            // Update rate
            $rate->update([
                'transaction_type' => $request->transaction_type,
                'rate_percentage' => $request->rate_percentage,
                'effective_date' => $request->effective_date,
                'updated_by' => $user->id,
            ]);

            $rate->load('updatedBy:id,full_name,email', 'cashAccount:id,code,name');

            return $this->successResponse(
                $rate,
                'Interest rate updated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Interest rate not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update interest rate: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Delete an interest rate.
     * 
     * Business Logic:
     * - Only Admin can delete
     * - Can only delete future rates (not yet effective)
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $rate = InterestRate::findOrFail($id);

            // Check if rate is already effective
            if ($rate->effective_date < now()->toDateString()) {
                return $this->errorResponse(
                    'Cannot delete interest rate that is already effective',
                    400
                );
            }

            $rate->delete();

            return $this->successResponse(
                null,
                'Interest rate deleted successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Interest rate not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to delete interest rate: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get current effective rates for all cash accounts.
     * 
     * Useful for dashboard/summary view
     *
     * @return JsonResponse
     */
    public function getCurrentRates(): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = CashAccount::active();

            // Access Control
            if ($user->isManager()) {
                $query->managedBy($user->id);
            }

            $cashAccounts = $query->get()->map(function($account) {
                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'savings_rate' => $account->currentSavingsRate()?->rate_percentage,
                    'loan_rate' => $account->currentLoanRate()?->rate_percentage,
                ];
            });

            return $this->successResponse(
                $cashAccounts,
                'Current rates retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve current rates: ' . $e->getMessage(),
                500
            );
        }
    }
}