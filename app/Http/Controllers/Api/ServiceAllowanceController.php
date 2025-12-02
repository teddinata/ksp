<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

            // Filters
            if ($request->has('user_id') && ($user->isAdmin() || $user->isManager())) {
                $query->byUser($request->user_id);
            }

            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            if ($request->has('year')) {
                $query->byYear($request->year);
            }

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
            } else {
                $allowances = $query->paginate($perPage);
            }

            return $request->has('all') 
                ? $this->successResponse($allowances, 'Service allowances retrieved successfully')
                : $this->paginatedResponse($allowances, 'Service allowances retrieved successfully');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve service allowances: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified service allowance.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = ServiceAllowance::with([
                'user:id,full_name,employee_id,email',
                'distributedBy:id,full_name'
            ]);

            // Access Control
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            $allowance = $query->findOrFail($id);

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
     * ✅ NEW: Store (input manual) jasa pelayanan untuk 1 member
     * 
     * Business Logic:
     * - Admin/Manager input manual per member per period
     * - System auto-potong cicilan bulan itu
     * - Jika kurang, member bayar sisa
     * - Jika lebih, sisa dikembalikan
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // Validation
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'period_month' => 'required|integer|min:1|max:12',
                'period_year' => 'required|integer|min:2020|max:2100',
                'received_amount' => 'required|numeric|min:0',
                'notes' => 'nullable|string',
            ]);

            $member = User::findOrFail($validated['user_id']);

            // Check if member role
            if (!$member->isMember()) {
                return $this->errorResponse(
                    'User is not a member',
                    400
                );
            }

            // Process service allowance
            $result = ServiceAllowance::processForMember(
                $member,
                $validated['period_month'],
                $validated['period_year'],
                $validated['received_amount'],
                $user->id,
                $validated['notes'] ?? null
            );

            return $this->successResponse(
                $result,
                'Jasa pelayanan berhasil diproses',
                201
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $e->errors()
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to process service allowance: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Preview calculation before processing
     */
    public function preview(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'period_month' => 'required|integer|min:1|max:12',
                'period_year' => 'required|integer|min:2020|max:2100',
                'received_amount' => 'required|numeric|min:0',
            ]);

            $member = User::findOrFail($request->user_id);

            // Get installments for preview
            $startDate = \Carbon\Carbon::create($request->period_year, $request->period_month, 1)->startOfMonth();
            $endDate = \Carbon\Carbon::create($request->period_year, $request->period_month, 1)->endOfMonth();
            
            $installments = \App\Models\Installment::whereHas('loan', function($q) use ($member) {
                $q->where('user_id', $member->id)
                  ->whereIn('status', ['disbursed', 'active']);
            })
            ->where('status', 'pending')
            ->whereBetween('due_date', [$startDate, $endDate])
            ->with('loan:id,loan_number')
            ->get();
            
            $totalDue = $installments->sum('total_amount');
            $receivedAmount = $request->received_amount;
            
            // Calculate preview
            if ($receivedAmount >= $totalDue) {
                $scenario = 'sufficient';
                $paidAmount = $totalDue;
                $remaining = $receivedAmount - $totalDue;
                $memberMustPay = 0;
            } else {
                $scenario = 'insufficient';
                $paidAmount = $receivedAmount;
                $remaining = 0;
                $memberMustPay = $totalDue - $receivedAmount;
            }

            return $this->successResponse([
                'member' => $member->only(['id', 'full_name', 'employee_id']),
                'period' => \Carbon\Carbon::create($request->period_year, $request->period_month, 1)->format('F Y'),
                'received_amount' => $receivedAmount,
                'installments' => $installments->map(function($inst) {
                    return [
                        'id' => $inst->id,
                        'loan_number' => $inst->loan->loan_number,
                        'installment_number' => $inst->installment_number,
                        'amount' => $inst->total_amount,
                        'due_date' => $inst->due_date->format('Y-m-d'),
                    ];
                }),
                'calculation' => [
                    'total_installments_due' => $totalDue,
                    'will_be_paid_from_allowance' => $paidAmount,
                    'remaining_for_member' => $remaining,
                    'member_must_pay' => $memberMustPay,
                    'scenario' => $scenario,
                    'message' => $memberMustPay > 0
                        ? "Jasa pelayanan kurang. Member harus bayar sisa: Rp " . number_format($memberMustPay, 0, ',', '.')
                        : ($remaining > 0
                            ? "Jasa pelayanan cukup. Sisa untuk member: Rp " . number_format($remaining, 0, ',', '.')
                            : "Jasa pelayanan pas untuk bayar cicilan."
                        ),
                ],
            ], 'Preview calculation retrieved successfully');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to calculate preview: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get service allowance summary for a period
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
                'total_received' => $allowances->sum('received_amount'),
                'total_paid_for_installments' => $allowances->sum('installment_paid'),
                'total_remaining_for_members' => $allowances->sum('remaining_amount'),
                'processed_count' => $allowances->where('status', 'processed')->count(),
                'pending_count' => $allowances->where('status', 'pending')->count(),
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
     * Get member's service allowance history
     */
    public function memberHistory(Request $request, int $userId): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control
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
                'total_remaining' => ServiceAllowance::getMemberTotalRemainingForYear($userId, $year),
                'allowances' => $allowances->map(function($allowance) {
                    return [
                        'id' => $allowance->id,
                        'period' => $allowance->period_display,
                        'received_amount' => $allowance->received_amount,
                        'installment_paid' => $allowance->installment_paid,
                        'remaining_amount' => $allowance->remaining_amount,
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