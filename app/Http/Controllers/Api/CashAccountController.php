<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashAccountRequest;
use App\Models\CashAccount;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashAccountController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of cash accounts.
     * 
     * Business Logic:
     * - Admin: Can see ALL cash accounts
     * - Manager: Can see ONLY assigned cash accounts
     * - Member: Cannot access (blocked by middleware)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = CashAccount::query();

            // Access Control: Manager only sees assigned kas
            if ($user->isManager()) {
                $query->managedBy($user->id);
            }
            // Admin sees all (no filter needed)

            // Filter by type
            if ($request->has('type')) {
                $query->byType($request->type);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search by code or name
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                      ->orWhere('name', 'like', "%{$search}%");
                });
            }

            // Include relationships
            $query->with(['activeManagers:id,full_name,email']);

            // Sort
            $sortBy = $request->get('sort_by', 'code');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                $cashAccounts = $query->get();
                return $this->successResponse($cashAccounts, 'Cash accounts retrieved successfully');
            } else {
                $cashAccounts = $query->paginate($perPage);
                return $this->paginatedResponse($cashAccounts, 'Cash accounts retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve cash accounts: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Store a newly created cash account.
     * 
     * Business Logic:
     * - Only Admin and Manager can create
     * - opening_balance defaults to 0
     * - current_balance defaults to opening_balance
     * - is_active defaults to true
     *
     * @param CashAccountRequest $request
     * @return JsonResponse
     */
    public function store(CashAccountRequest $request): JsonResponse
    {
        try {
            // Set defaults
            $openingBalance = $request->opening_balance ?? 0;
            $currentBalance = $request->current_balance ?? $openingBalance;

            $cashAccount = CashAccount::create([
                'code' => $request->code,
                'name' => $request->name,
                'type' => $request->type,
                'opening_balance' => $openingBalance,
                'current_balance' => $currentBalance,
                'description' => $request->description,
                'is_active' => $request->is_active ?? true,
            ]);

            // Load relationships
            $cashAccount->load('activeManagers:id,full_name,email');

            return $this->successResponse(
                $cashAccount,
                'Cash account created successfully',
                201
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create cash account: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified cash account.
     * 
     * Business Logic:
     * - Admin: Can see any cash account
     * - Manager: Can only see assigned cash accounts
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = CashAccount::with([
                'activeManagers:id,full_name,email,role',
                'interestRates' => function($q) {
                    $q->orderBy('effective_date', 'desc')->limit(5);
                }
            ]);

            // Access Control
            if ($user->isManager()) {
                $query->managedBy($user->id);
            }

            $cashAccount = $query->findOrFail($id);

            // Add current rates to response
            $cashAccount->current_savings_rate = $cashAccount->currentSavingsRate();
            $cashAccount->current_loan_rate = $cashAccount->currentLoanRate();

            return $this->successResponse(
                $cashAccount,
                'Cash account retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                'Cash account not found or you do not have access',
                404
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve cash account: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update the specified cash account.
     * 
     * Business Logic:
     * - Only Admin can update
     * - Manager cannot update (can only manage assigned kas)
     * - Cannot update balance directly (use transactions instead)
     *
     * @param CashAccountRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(CashAccountRequest $request, int $id): JsonResponse
    {
        try {
            $cashAccount = CashAccount::findOrFail($id);

            // Update fields (except balance - use transactions for that)
            $cashAccount->update([
                'code' => $request->code,
                'name' => $request->name,
                'type' => $request->type,
                'description' => $request->description,
                'is_active' => $request->is_active ?? $cashAccount->is_active,
                // Note: opening_balance & current_balance NOT updated here
            ]);

            $cashAccount->load('activeManagers:id,full_name,email');

            return $this->successResponse(
                $cashAccount,
                'Cash account updated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Cash account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update cash account: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove the specified cash account.
     * 
     * Business Logic:
     * - Only Admin can delete
     * - Soft delete (data not actually removed)
     * - Future: Check if has transactions before delete
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $cashAccount = CashAccount::findOrFail($id);

            // TODO: Future enhancement - Check if has transactions
            // if ($cashAccount->hasTransactions()) {
            //     return $this->errorResponse(
            //         'Cannot delete cash account with existing transactions',
            //         400
            //     );
            // }

            $cashAccount->delete();

            return $this->successResponse(
                null,
                'Cash account deleted successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Cash account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to delete cash account: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get summary of all cash accounts.
     * 
     * Returns total balance per type
     *
     * @return JsonResponse
     */
    public function getSummary(): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = CashAccount::active();

            // Access Control
            if ($user->isManager()) {
                $query->managedBy($user->id);
            }

            $summary = [
                'total_accounts' => $query->count(),
                'total_balance' => $query->sum('current_balance'),
                'by_type' => $query->selectRaw('type, COUNT(*) as count, SUM(current_balance) as balance')
                                  ->groupBy('type')
                                  ->get()
                                  ->map(function($item) {
                                      return [
                                          'type' => $item->type,
                                          'type_name' => match($item->type) {
                                              'I' => 'Kas Umum',
                                              'II' => 'Kas Sosial',
                                              'III' => 'Kas Pengadaan',
                                              'IV' => 'Kas Hadiah',
                                              'V' => 'Bank',
                                              default => $item->type,
                                          },
                                          'count' => $item->count,
                                          'balance' => (float) $item->balance,
                                      ];
                                  }),
            ];

            return $this->successResponse(
                $summary,
                'Cash accounts summary retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve summary: ' . $e->getMessage(),
                500
            );
        }
    }
}