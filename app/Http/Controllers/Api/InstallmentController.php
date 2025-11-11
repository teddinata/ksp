<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PayInstallmentRequest;
use App\Models\Installment;
use App\Models\Loan;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstallmentController extends Controller
{
    use ApiResponse;

    /**
     * Display installments for a loan.
     *
     * @param int $loanId
     * @return JsonResponse
     */
    public function index(int $loanId): JsonResponse
    {
        try {
            $user = auth()->user();
            
            // Get loan with access control
            $loanQuery = Loan::query();
            if ($user->isMember()) {
                $loanQuery->byUser($user->id);
            }
            
            $loan = $loanQuery->findOrFail($loanId);

            // Update overdue status
            $loan->updateOverdueStatus();

            $installments = $loan->installments()
                ->with('confirmedBy:id,full_name')
                ->orderBy('installment_number')
                ->get();

            return $this->successResponse(
                $installments,
                'Installments retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Loan not found or access denied', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve installments: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified installment.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $installment = Installment::with([
                'loan.user:id,full_name,employee_id',
                'loan.cashAccount:id,code,name',
                'confirmedBy:id,full_name'
            ])->findOrFail($id);

            // Access Control
            if ($user->isMember() && $installment->loan->user_id != $user->id) {
                return $this->errorResponse('Access denied', 403);
            }

            // Add computed attributes
            $installment->days_overdue = $installment->days_overdue;
            $installment->days_until_due = $installment->days_until_due;

            return $this->successResponse(
                $installment,
                'Installment retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Installment not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve installment: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Pay an installment.
     * 
     * Business Logic:
     * - Check if installment is pending/overdue
     * - Mark as paid with payment method
     * - If service_allowance: auto_paid
     * - If manual: requires confirmation
     * - Check if loan is fully paid
     *
     * @param PayInstallmentRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function pay(PayInstallmentRequest $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $installment = Installment::with('loan')->findOrFail($id);

            // Access Control
            if ($user->isMember() && $installment->loan->user_id != $user->id) {
                return $this->errorResponse('Access denied', 403);
            }

            // Check if already paid
            if ($installment->isPaid()) {
                return $this->errorResponse(
                    'Installment is already paid',
                    400
                );
            }

            // Check if pending or overdue
            if (!in_array($installment->status, ['pending', 'overdue'])) {
                return $this->errorResponse(
                    'Cannot pay installment with status: ' . $installment->status,
                    400
                );
            }

            // Mark as paid
            $installment->markAsPaid(
                $request->payment_method,
                $user->id,
                $request->notes
            );

            $installment->load(['loan.user', 'loan.cashAccount', 'confirmedBy']);

            return $this->successResponse(
                $installment,
                'Installment paid successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Installment not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to pay installment: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get upcoming installments (due soon).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function upcoming(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $days = $request->get('days', 7); // Default 7 days

            $query = Installment::with([
                'loan.user:id,full_name,employee_id',
                'loan.cashAccount:id,code,name'
            ])
            ->whereHas('loan', function($q) use ($user) {
                if ($user->isMember()) {
                    $q->where('user_id', $user->id);
                }
                $q->whereIn('status', ['active', 'disbursed']);
            })
            ->where('status', 'pending')
            ->whereBetween('due_date', [now(), now()->addDays($days)])
            ->orderBy('due_date');

            $installments = $query->get();

            return $this->successResponse(
                $installments,
                'Upcoming installments retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve upcoming installments: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get overdue installments.
     *
     * @return JsonResponse
     */
    public function overdue(): JsonResponse
    {
        try {
            $user = auth()->user();

            // Update overdue status first
            Installment::checkOverdueInstallments();

            $query = Installment::with([
                'loan.user:id,full_name,employee_id',
                'loan.cashAccount:id,code,name'
            ])
            ->whereHas('loan', function($q) use ($user) {
                if ($user->isMember()) {
                    $q->where('user_id', $user->id);
                }
                $q->whereIn('status', ['active', 'disbursed']);
            })
            ->where('status', 'overdue')
            ->orderBy('due_date');

            $installments = $query->get()->map(function($installment) {
                $installment->days_overdue = $installment->days_overdue;
                return $installment;
            });

            return $this->successResponse(
                $installments,
                'Overdue installments retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve overdue installments: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get installment schedule for a loan.
     *
     * @param int $loanId
     * @return JsonResponse
     */
    public function schedule(int $loanId): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $loanQuery = Loan::query();
            if ($user->isMember()) {
                $loanQuery->byUser($user->id);
            }
            
            $loan = $loanQuery->findOrFail($loanId);

            $schedule = $loan->installments()
                ->orderBy('installment_number')
                ->get()
                ->map(function($installment) {
                    return [
                        'installment_number' => $installment->installment_number,
                        'due_date' => $installment->due_date->format('Y-m-d'),
                        'principal_amount' => $installment->principal_amount,
                        'interest_amount' => $installment->interest_amount,
                        'total_amount' => $installment->total_amount,
                        'remaining_principal' => $installment->remaining_principal,
                        'status' => $installment->status,
                        'status_name' => $installment->status_name,
                        'payment_date' => $installment->payment_date?->format('Y-m-d'),
                    ];
                });

            return $this->successResponse(
                [
                    'loan' => $loan->only(['id', 'loan_number', 'principal_amount', 'tenure_months']),
                    'schedule' => $schedule,
                ],
                'Installment schedule retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Loan not found or access denied', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve schedule: ' . $e->getMessage(),
                500
            );
        }
    }
}