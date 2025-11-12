<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceAllowanceRequest;
use App\Models\ServiceAllowance;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceAllowanceController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of service allowances.
     * 
     * Business Logic:
     * - Admin/Manager: Can see all allowances
     * - Member: Can only see their own allowances
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = ServiceAllowance::with([
                'user:id,full_name,employee_id',
                'distributedBy:id,full_name'
            ]);

            // Access Control: Member only sees own allowances
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            // Filter by user
            if ($request->has('user_id') && ($user->isAdmin() || $user->isManager())) {
                $query->byUser($request->user_id);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            // Filter by year
            if ($request->has('year')) {
                $query->byYear($request->year);
            }

            // Filter by period
            if ($request->has('month') && $request->has('year')) {
                $query->byPeriod($request->month, $request->year);
            }

            // Sort
            $sortBy = $request->get('sort_by', 'period_year');
            $sortOrder = $request->get('sort_order', 'desc');
            
            $query->orderBy($sortBy, $sortOrder);
            
            if ($sortBy !== 'period_month') {
                $query->orderBy('period_month', 'desc');
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                $allowances = $query->get();
                
                // Add computed attributes
                $allowances->each(function($allowance) {
                    $allowance->period_display = $allowance->period_display;
                    $allowance->status_name = $allowance->status_name;
                });

                return $this->successResponse($allowances, 'Service allowances retrieved successfully');
            } else {
                $allowances = $query->paginate($perPage);
                
                // Add computed attributes
                $allowances->getCollection()->each(function($allowance) {
                    $allowance->period_display = $allowance->period_display;
                    $allowance->status_name = $allowance->status_name;
                });

                return $this->paginatedResponse($allowances, 'Service allowances retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve service allowances: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified service allowance.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = ServiceAllowance::with([
                'user:id,full_name,employee_id,email',
                'distributedBy:id,full_name'
            ]);

            // Access Control: Member only sees own allowances
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            $allowance = $query->findOrFail($id);

            // Add computed attributes
            $allowance->period_display = $allowance->period_display;
            $allowance->status_name = $allowance->status_name;

            return $this->successResponse(
                $allowance,
                'Service allowance retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Service allowance not found or access denied', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve service allowance: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Distribute service allowances to all members.
     *
     * @param ServiceAllowanceRequest $request
     * @return JsonResponse
     */
    public function distribute(ServiceAllowanceRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $result = ServiceAllowance::distributeToMembers(
                $request->period_month,
                $request->period_year,
                $user->id,
                [
                    'base_amount' => $request->base_amount,
                    'savings_rate' => $request->savings_rate,
                    'loan_rate' => $request->loan_rate,
                ]
            );

            return $this->successResponse(
                $result,
                'Service allowances distributed successfully',
                201
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to distribute service allowances: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Mark service allowance as paid.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function markAsPaid(Request $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $allowance = ServiceAllowance::findOrFail($id);

            // Check if already paid
            if ($allowance->isPaid()) {
                return $this->errorResponse(
                    'Service allowance is already paid',
                    400
                );
            }

            $allowance->markAsPaid($user->id, $request->notes);

            $allowance->load(['user:id,full_name,employee_id', 'distributedBy:id,full_name']);

            return $this->successResponse(
                $allowance,
                'Service allowance marked as paid successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Service allowance not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to mark as paid: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Calculate service allowance for a member (preview).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function calculate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'period_month' => 'required|integer|min:1|max:12',
                'period_year' => 'required|integer|min:2020|max:2100',
                'base_amount' => 'required|numeric|min:0',
                'savings_rate' => 'required|numeric|min:0|max:100',
                'loan_rate' => 'required|numeric|min:0|max:100',
            ]);

            $user = User::findOrFail($request->user_id);

            $calculation = ServiceAllowance::calculateForMember(
                $user,
                $request->period_month,
                $request->period_year,
                $request->base_amount,
                $request->savings_rate,
                $request->loan_rate
            );

            return $this->successResponse(
                [
                    'user' => $user->only(['id', 'full_name', 'employee_id']),
                    'period' => \Carbon\Carbon::create($request->period_year, $request->period_month, 1)->format('F Y'),
                    'calculation' => $calculation,
                ],
                'Service allowance calculated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('User not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to calculate service allowance: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get service allowance summary for a period.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function periodSummary(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'month' => 'required|integer|min:1|max:12',
                'year' => 'required|integer|min:2020|max:2100',
            ]);

            $month = $request->month;
            $year = $request->year;

            $allowances = ServiceAllowance::with('user:id,full_name,employee_id')
                ->byPeriod($month, $year)
                ->get();

            if ($allowances->isEmpty()) {
                return $this->errorResponse(
                    'No service allowances found for this period',
                    404
                );
            }

            $summary = [
                'period' => \Carbon\Carbon::create($year, $month, 1)->format('F Y'),
                'total_members' => $allowances->count(),
                'total_amount' => $allowances->sum('total_amount'),
                'total_base' => $allowances->sum('base_amount'),
                'total_savings_bonus' => $allowances->sum('savings_bonus'),
                'total_loan_bonus' => $allowances->sum('loan_bonus'),
                'paid_count' => $allowances->where('status', 'paid')->count(),
                'pending_count' => $allowances->where('status', 'pending')->count(),
                'paid_amount' => $allowances->where('status', 'paid')->sum('total_amount'),
                'pending_amount' => $allowances->where('status', 'pending')->sum('total_amount'),
            ];

            return $this->successResponse(
                $summary,
                'Period summary retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve period summary: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get member's service allowance history.
     *
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function memberHistory(Request $request, int $userId): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Members can only see their own history
            if ($user->isMember() && $user->id != $userId) {
                return $this->errorResponse('Access denied', 403);
            }

            $member = User::findOrFail($userId);

            $year = $request->get('year', now()->year);

            $allowances = ServiceAllowance::where('user_id', $userId)
                ->byYear($year)
                ->orderBy('period_month', 'desc')
                ->get();

            $history = [
                'user' => $member->only(['id', 'full_name', 'employee_id']),
                'year' => $year,
                'total_received' => ServiceAllowance::getMemberTotalForYear($userId, $year),
                'allowances' => $allowances->map(function($allowance) {
                    return [
                        'id' => $allowance->id,
                        'period' => $allowance->period_display,
                        'base_amount' => $allowance->base_amount,
                        'savings_bonus' => $allowance->savings_bonus,
                        'loan_bonus' => $allowance->loan_bonus,
                        'total_amount' => $allowance->total_amount,
                        'status' => $allowance->status,
                        'status_name' => $allowance->status_name,
                        'payment_date' => $allowance->payment_date?->format('Y-m-d'),
                    ];
                }),
            ];

            return $this->successResponse(
                $history,
                'Member history retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Member not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve member history: ' . $e->getMessage(),
                500
            );
        }
    }
}