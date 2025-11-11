<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavingRequest;
use App\Http\Requests\ApproveSavingRequest;
use App\Models\Saving;
use App\Models\CashAccount;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavingController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of savings.
     * 
     * Business Logic:
     * - Admin/Manager: Can see all savings
     * - Member: Can only see their own savings
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Saving::with(['user:id,full_name,employee_id', 'cashAccount:id,code,name', 'approvedBy:id,full_name']);

            // Access Control: Member only sees own savings
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            // Filter by user
            if ($request->has('user_id') && ($user->isAdmin() || $user->isManager())) {
                $query->byUser($request->user_id);
            }

            // Filter by cash account
            if ($request->has('cash_account_id')) {
                $query->byCashAccount($request->cash_account_id);
            }

            // Filter by savings type
            if ($request->has('savings_type')) {
                $query->byType($request->savings_type);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->dateRange($request->start_date, $request->end_date);
            }

            // Search by user name or employee ID
            if ($request->has('search') && ($user->isAdmin() || $user->isManager())) {
                $search = $request->search;
                $query->whereHas('user', function($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('employee_id', 'like', "%{$search}%");
                });
            }

            // Sort
            $sortBy = $request->get('sort_by', 'transaction_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                $savings = $query->get();
                return $this->successResponse($savings, 'Savings retrieved successfully');
            } else {
                $savings = $query->paginate($perPage);
                return $this->paginatedResponse($savings, 'Savings retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve savings: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Store a newly created saving.
     * 
     * Business Logic:
     * - Get interest rate from cash account
     * - Calculate final amount with interest
     * - Status = approved (auto-approved)
     * - Update cash account balance
     *
     * @param SavingRequest $request
     * @return JsonResponse
     */
    public function store(SavingRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            // Get cash account and interest rate
            $cashAccount = CashAccount::findOrFail($request->cash_account_id);
            $interestRate = $cashAccount->currentSavingsRate();
            $interestPercentage = $interestRate ? $interestRate->rate_percentage : 0;

            // Calculate final amount with interest
            $finalAmount = Saving::calculateFinalAmount($request->amount, $interestPercentage);

            // Create saving
            $saving = Saving::create([
                'user_id' => $request->user_id,
                'cash_account_id' => $request->cash_account_id,
                'savings_type' => $request->savings_type,
                'amount' => $request->amount,
                'interest_percentage' => $interestPercentage,
                'final_amount' => $finalAmount,
                'transaction_date' => $request->transaction_date,
                'status' => 'approved', // Auto-approved
                'notes' => $request->notes,
                'approved_by' => $user->id,
            ]);

            // Update cash account balance
            $cashAccount->updateBalance($request->amount, 'add');

            // Load relationships
            $saving->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name', 'approvedBy:id,full_name']);

            return $this->successResponse(
                $saving,
                'Saving transaction created successfully',
                201
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Cash account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create saving: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified saving.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Saving::with(['user:id,full_name,employee_id,email', 'cashAccount:id,code,name', 'approvedBy:id,full_name']);

            // Access Control: Member only sees own savings
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            $saving = $query->findOrFail($id);

            return $this->successResponse(
                $saving,
                'Saving retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Saving not found or access denied', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve saving: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update the specified saving.
     * 
     * Business Logic:
     * - Only pending savings can be updated
     * - Cannot update approved/rejected savings
     *
     * @param SavingRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SavingRequest $request, int $id): JsonResponse
    {
        try {
            $saving = Saving::findOrFail($id);

            // Check if saving is still pending
            if (!$saving->isPending()) {
                return $this->errorResponse(
                    'Cannot update saving that is already ' . $saving->status,
                    400
                );
            }

            // Get interest rate
            $cashAccount = CashAccount::findOrFail($request->cash_account_id);
            $interestRate = $cashAccount->currentSavingsRate();
            $interestPercentage = $interestRate ? $interestRate->rate_percentage : 0;

            // Calculate final amount
            $finalAmount = Saving::calculateFinalAmount($request->amount, $interestPercentage);

            $saving->update([
                'user_id' => $request->user_id,
                'cash_account_id' => $request->cash_account_id,
                'savings_type' => $request->savings_type,
                'amount' => $request->amount,
                'interest_percentage' => $interestPercentage,
                'final_amount' => $finalAmount,
                'transaction_date' => $request->transaction_date,
                'notes' => $request->notes,
            ]);

            $saving->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name']);

            return $this->successResponse(
                $saving,
                'Saving updated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Saving not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update saving: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove the specified saving.
     * 
     * Business Logic:
     * - Only pending savings can be deleted
     * - Approved savings cannot be deleted (soft delete only)
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $saving = Saving::findOrFail($id);

            // Check if approved (should reverse balance first)
            if ($saving->isApproved()) {
                return $this->errorResponse(
                    'Cannot delete approved saving. Please create a reversal transaction instead.',
                    400
                );
            }

            $saving->delete();

            return $this->successResponse(
                null,
                'Saving deleted successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Saving not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to delete saving: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Approve or reject a saving.
     *
     * @param ApproveSavingRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function approve(ApproveSavingRequest $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $saving = Saving::findOrFail($id);

            // Check if still pending
            if (!$saving->isPending()) {
                return $this->errorResponse(
                    'Saving is already ' . $saving->status,
                    400
                );
            }

            $saving->update([
                'status' => $request->status,
                'notes' => $request->notes ?? $saving->notes,
                'approved_by' => $user->id,
            ]);

            // If approved, update cash account balance
            if ($request->status === 'approved') {
                $cashAccount = CashAccount::find($saving->cash_account_id);
                if ($cashAccount) {
                    $cashAccount->updateBalance($saving->amount, 'add');
                }
            }

            $saving->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name', 'approvedBy:id,full_name']);

            $message = $request->status === 'approved' 
                ? 'Saving approved successfully' 
                : 'Saving rejected successfully';

            return $this->successResponse($saving, $message);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Saving not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to process approval: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get savings summary for a user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSummary(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            // User ID to get summary for
            $userId = $request->get('user_id', $user->id);

            // Access Control: Members can only see own summary
            if ($user->isMember() && $userId != $user->id) {
                return $this->errorResponse('Access denied', 403);
            }

            $summary = [
                'user' => \App\Models\User::find($userId)->only(['id', 'full_name', 'employee_id']),
                'total_savings' => Saving::getTotalForUser($userId),
                'by_type' => [
                    'principal' => Saving::getTotalByType($userId, 'principal'),
                    'mandatory' => Saving::getTotalByType($userId, 'mandatory'),
                    'voluntary' => Saving::getTotalByType($userId, 'voluntary'),
                    'holiday' => Saving::getTotalByType($userId, 'holiday'),
                ],
                'transaction_count' => Saving::where('user_id', $userId)
                    ->where('status', 'approved')
                    ->count(),
                'pending_count' => Saving::where('user_id', $userId)
                    ->where('status', 'pending')
                    ->count(),
            ];

            return $this->successResponse(
                $summary,
                'Savings summary retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve summary: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get savings by type for a user.
     *
     * @param Request $request
     * @param string $type
     * @return JsonResponse
     */
    public function getByType(Request $request, string $type): JsonResponse
    {
        try {
            $user = auth()->user();
            
            // Validate type
            if (!in_array($type, ['principal', 'mandatory', 'voluntary', 'holiday'])) {
                return $this->errorResponse('Invalid savings type', 400);
            }

            $query = Saving::with(['user:id,full_name,employee_id', 'cashAccount:id,code,name'])
                ->byType($type)
                ->approved();

            // Access Control: Members see only their own
            if ($user->isMember()) {
                $query->byUser($user->id);
            } elseif ($request->has('user_id')) {
                $query->byUser($request->user_id);
            }

            $savings = $query->orderBy('transaction_date', 'desc')->get();

            return $this->successResponse(
                $savings,
                ucfirst($type) . ' savings retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve savings: ' . $e->getMessage(),
                500
            );
        }
    }
}