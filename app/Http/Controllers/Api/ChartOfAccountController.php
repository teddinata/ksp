<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChartOfAccountRequest;
use App\Models\ChartOfAccount;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChartOfAccountController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of chart of accounts.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ChartOfAccount::query();

            // Filter by category
            if ($request->has('category')) {
                $query->byCategory($request->category);
            }

            // Filter by account type
            if ($request->has('account_type')) {
                $query->byType($request->account_type);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Filter by balance type (debit/credit)
            if ($request->has('is_debit')) {
                if ($request->boolean('is_debit')) {
                    $query->debitAccounts();
                } else {
                    $query->creditAccounts();
                }
            }

            // Search by code or name
            if ($request->has('search')) {
                $query->search($request->search);
            }

            // Sort
            $sortBy = $request->get('sort_by', 'code');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                // Get all without pagination
                $accounts = $query->get();
                
                return $this->successResponse($accounts, 'Chart of accounts retrieved successfully');
            } else {
                // With pagination
                $accounts = $query->paginate($perPage);
                
                return $this->paginatedResponse($accounts, 'Chart of accounts retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve chart of accounts: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Store a newly created account.
     *
     * @param ChartOfAccountRequest $request
     * @return JsonResponse
     */
    public function store(ChartOfAccountRequest $request): JsonResponse
    {
        try {
            $account = ChartOfAccount::create([
                'code' => $request->code,
                'name' => $request->name,
                'category' => $request->category,
                'account_type' => $request->account_type,
                'is_debit' => $request->is_debit,
                'is_active' => $request->is_active ?? true,
                'description' => $request->description,
            ]);

            return $this->successResponse(
                $account,
                'Chart of account created successfully',
                201
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create chart of account: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified account.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $account = ChartOfAccount::findOrFail($id);

            return $this->successResponse(
                $account,
                'Chart of account retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Chart of account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve chart of account: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update the specified account.
     *
     * @param ChartOfAccountRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(ChartOfAccountRequest $request, int $id): JsonResponse
    {
        try {
            $account = ChartOfAccount::findOrFail($id);

            $account->update([
                'code' => $request->code,
                'name' => $request->name,
                'category' => $request->category,
                'account_type' => $request->account_type,
                'is_debit' => $request->is_debit,
                'is_active' => $request->is_active ?? $account->is_active,
                'description' => $request->description,
            ]);

            return $this->successResponse(
                $account,
                'Chart of account updated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Chart of account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update chart of account: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove the specified account.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $account = ChartOfAccount::findOrFail($id);

            // Check if account is used in transactions (optional - implement later)
            // if ($account->journalDetails()->exists()) {
            //     return $this->errorResponse(
            //         'Cannot delete account that has transactions',
            //         400
            //     );
            // }

            $account->delete();

            return $this->successResponse(
                null,
                'Chart of account deleted successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Chart of account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to delete chart of account: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get accounts by category.
     *
     * @param string $category
     * @return JsonResponse
     */
    public function getByCategory(string $category): JsonResponse
    {
        try {
            $validCategories = ['assets', 'liabilities', 'equity', 'revenue', 'expenses'];
            
            if (!in_array($category, $validCategories)) {
                return $this->errorResponse('Invalid category', 400);
            }

            $accounts = ChartOfAccount::byCategory($category)
                ->active()
                ->orderBy('code')
                ->get();

            return $this->successResponse(
                $accounts,
                "Chart of accounts for {$category} retrieved successfully"
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve chart of accounts: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get account categories summary.
     *
     * @return JsonResponse
     */
    public function getCategorySummary(): JsonResponse
    {
        try {
            $summary = ChartOfAccount::selectRaw('category, count(*) as count')
                ->groupBy('category')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [$item->category => $item->count];
                });

            return $this->successResponse(
                $summary,
                'Category summary retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve category summary: ' . $e->getMessage(),
                500
            );
        }
    }
}