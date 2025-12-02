<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignManagerRequest;
use App\Models\CashAccount;
use App\Models\CashAccountManager;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashAccountManagerController extends Controller
{
    use ApiResponse;

    /**
     * Get managers assigned to a cash account.
     * 
     * Business Logic:
     * - Shows all managers (active and inactive)
     * - Includes assignment date and status
     *
     * @param int $cashAccountId
     * @return JsonResponse
     */
    public function index(int $cashAccountId): JsonResponse
    {
        try {
            $cashAccount = CashAccount::findOrFail($cashAccountId);

            $managers = $cashAccount->managers()
                ->withPivot('assigned_at', 'is_active')
                ->get()
                ->map(function($manager) {
                    return [
                        'id' => $manager->id,
                        'full_name' => $manager->full_name,
                        'employee_id' => $manager->employee_id,
                        'email' => $manager->email,
                        'role' => $manager->role,
                        'assigned_at' => $manager->pivot->assigned_at,
                        'is_active' => $manager->pivot->is_active,
                    ];
                });

            return $this->successResponse(
                $managers,
                'Managers retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Cash account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve managers: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Assign a manager to a cash account.
     * 
     * Business Logic:
     * - Only Admin can assign managers
     * - User must have 'manager' or 'admin' role
     * - Can assign same manager to multiple kas
     * - If already assigned, reactivate instead of duplicate
     *
     * @param AssignManagerRequest $request
     * @param int $cashAccountId
     * @return JsonResponse
     */
    public function store(AssignManagerRequest $request, int $cashAccountId): JsonResponse
    {
        try {
            $cashAccount = CashAccount::findOrFail($cashAccountId);
            $manager = User::findOrFail($request->manager_id);

            // Check if already assigned
            $existing = CashAccountManager::where('cash_account_id', $cashAccountId)
                ->where('manager_id', $request->manager_id)
                ->first();

            if ($existing) {
                // If inactive, reactivate
                if (!$existing->is_active) {
                    $existing->update([
                        'is_active' => true,
                        'assigned_at' => $request->assigned_at ?? now(),
                    ]);

                    return $this->successResponse(
                        [
                            'cash_account' => $cashAccount->only(['id', 'code', 'name']),
                            'manager' => $manager->only(['id', 'full_name', 'email']),
                            'assigned_at' => $existing->assigned_at,
                            'is_active' => $existing->is_active,
                        ],
                        'Manager reactivated successfully'
                    );
                }

                return $this->errorResponse(
                    'Manager already assigned to this cash account',
                    400
                );
            }

            // Create new assignment
            $assignment = CashAccountManager::create([
                'manager_id' => $request->manager_id,
                'cash_account_id' => $cashAccountId,
                'assigned_at' => $request->assigned_at ?? now(),
                'is_active' => true,
            ]);

            return $this->successResponse(
                [
                    'cash_account' => $cashAccount->only(['id', 'code', 'name']),
                    'manager' => $manager->only(['id', 'full_name', 'email']),
                    'assigned_at' => $assignment->assigned_at,
                    'is_active' => $assignment->is_active,
                ],
                'Manager assigned successfully',
                201
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Cash account or manager not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to assign manager: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove (deactivate) manager assignment.
     * 
     * Business Logic:
     * - Only Admin can remove managers
     * - Soft deactivate (set is_active = false)
     * - Does not actually delete the record (for audit trail)
     *
     * @param int $cashAccountId
     * @param int $managerId
     * @return JsonResponse
     */
    public function destroy(int $cashAccountId, int $managerId): JsonResponse
    {
        try {
            $assignment = CashAccountManager::where('cash_account_id', $cashAccountId)
                ->where('manager_id', $managerId)
                ->firstOrFail();

            // Soft deactivate
            $assignment->update(['is_active' => false]);

            return $this->successResponse(
                null,
                'Manager assignment deactivated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Manager assignment not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to remove manager: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get all cash accounts managed by a specific user.
     * 
     * Useful for getting manager's dashboard
     *
     * @param int $managerId
     * @return JsonResponse
     */
    public function getManagedAccounts(int $managerId): JsonResponse
    {
        try {
            $manager = User::findOrFail($managerId);

            if (!$manager->isManager() && !$manager->isAdmin()) {
                return $this->errorResponse(
                    'User is not a manager',
                    400
                );
            }

            $cashAccounts = CashAccount::managedBy($managerId)
                ->active()
                ->with('currentSavingsRate', 'currentLoanRate')
                ->get();

            return $this->successResponse(
                [
                    'manager' => $manager->only(['id', 'full_name', 'email', 'role']),
                    'cash_accounts' => $cashAccounts,
                    'total_managed' => $cashAccounts->count(),
                ],
                'Managed accounts retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Manager not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve managed accounts: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * ✅ OPTIONAL ENHANCEMENT: Get available managers
     * 
     * List all users with admin/manager role that can be assigned
     */
    public function getAvailableManagers(): JsonResponse
    {
        try {
            $managers = User::where(function($q) {
                $q->where('role', 'admin')
                  ->orWhere('role', 'manager');
            })
            ->where('status', 'active')
            ->select('id', 'full_name', 'employee_id', 'email', 'role')
            ->orderBy('full_name')
            ->get();
            
            // Add info about current assignments
            $managers->each(function($manager) {
                $manager->assigned_cash_accounts = CashAccountManager::where('manager_id', $manager->id)
                    ->where('is_active', true)
                    ->with('cashAccount:id,code,name')
                    ->get()
                    ->map(function($assignment) {
                        return [
                            'id' => $assignment->cashAccount->id,
                            'code' => $assignment->cashAccount->code,
                            'name' => $assignment->cashAccount->name,
                            'assigned_at' => $assignment->assigned_at,
                        ];
                    });
                    
                $manager->managed_cash_accounts_count = $manager->assigned_cash_accounts->count();
            });
            
            return $this->successResponse(
                $managers,
                'Available managers retrieved successfully'
            );
            
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve managers: ' . $e->getMessage(),
                500
            );
        }
    }
}