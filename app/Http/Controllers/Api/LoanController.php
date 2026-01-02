<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanRequest;
use App\Http\Requests\ApproveLoanRequest;
use App\Models\Loan;
use App\Models\User;
use App\Models\CashAccount;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    use ApiResponse;

    /**
     * ✅ NEW: Check if user can apply for loan (eligibility check)
     * 
     * Business Logic:
     * - Check loan limit (max 1 per cash account)
     * - Check cash account type (only Kas I & III)
     * - Return available cash accounts
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function checkEligibility(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'cash_account_id' => 'nullable|exists:cash_accounts,id',
            ]);
            
            $user = User::findOrFail($validated['user_id']);
            
            // Check if user is a member
            if (!$user->isMember()) {
                return $this->errorResponse('Hanya member yang dapat mengajukan pinjaman', 400);
            }
            
            // Check if user is active
            if ($user->status !== 'active') {
                return $this->errorResponse('Member tidak aktif', 400);
            }
            
            $response = [
                'user' => $user->only(['id', 'full_name', 'employee_id', 'email']),
                'loan_summary' => $user->getLoanSummary(),
                'available_cash_accounts' => $user->getAvailableCashAccountsForLoan(),
            ];
            
            // If specific cash account is provided, check it
            if (isset($validated['cash_account_id'])) {
                $cashAccountId = $validated['cash_account_id'];
                $check = $user->canApplyForLoan($cashAccountId);
                
                $cashAccount = CashAccount::find($cashAccountId);
                
                $response['check_result'] = [
                    'cash_account' => $cashAccount ? [
                        'id' => $cashAccount->id,
                        'code' => $cashAccount->code,
                        'name' => $cashAccount->name,
                        'type' => $cashAccount->type,
                    ] : null,
                    'can_apply' => $check['can_apply'],
                    'reason' => $check['reason'],
                ];
            }
            
            return $this->successResponse(
                $response,
                'Eligibility checked successfully'
            );
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('User tidak ditemukan', 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                'Validasi gagal: ' . $e->getMessage(),
                422
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memeriksa kelayakan: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display a listing of loans.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Loan::with([
                'user:id,full_name,employee_id',
                'cashAccount:id,code,name',
                'approvedBy:id,full_name'
            ]);

            // Access Control: Member only sees own loans
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

            // Filter by status
            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            // Search by user name or loan number
            if ($request->has('search') && ($user->isAdmin() || $user->isManager())) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('loan_number', 'like', "%{$search}%")
                      ->orWhereHas('user', function($q2) use ($search) {
                          $q2->where('full_name', 'like', "%{$search}%")
                             ->orWhere('employee_id', 'like', "%{$search}%");
                      });
                });
            }

            // Sort
            $sortBy = $request->get('sort_by', 'application_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                $loans = $query->get();
                return $this->successResponse($loans, 'Loans retrieved successfully');
            } else {
                $loans = $query->paginate($perPage);
                return $this->paginatedResponse($loans, 'Loans retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve loans: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Store a newly created loan application.
     * ✅ UPDATED: Now includes loan limit validation via LoanRequest
     */
    public function store(LoanRequest $request): JsonResponse
    {
        try {
            // Get cash account and interest rate
            $cashAccount = CashAccount::findOrFail($request->cash_account_id);
            $interestRate = $cashAccount->currentLoanRate();
            $interestPercentage = $interestRate ? $interestRate->rate_percentage : 12.0;

            // Calculate monthly installment
            $installmentAmount = Loan::calculateInstallment(
                $request->principal_amount,
                $interestPercentage,
                $request->tenure_months
            );

            // Generate loan number
            $loanNumber = Loan::generateLoanNumber();

            // Create loan
            $loan = Loan::create([
                'user_id' => $request->user_id,
                'cash_account_id' => $request->cash_account_id,
                'loan_number' => $loanNumber,
                'principal_amount' => $request->principal_amount,
                'interest_percentage' => $interestPercentage,
                'tenure_months' => $request->tenure_months,
                'installment_amount' => $installmentAmount,
                'status' => 'pending',
                'application_date' => $request->application_date,
                'loan_purpose' => $request->loan_purpose,
                'document_path' => $request->document_path,
            ]);

            // Load relationships
            $loan->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name']);

            return $this->successResponse(
                $loan,
                'Pengajuan pinjaman berhasil. Menunggu persetujuan admin.',
                201
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Cash account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create loan application: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified loan.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Loan::with([
                'user:id,full_name,employee_id,email',
                'cashAccount:id,code,name',
                'approvedBy:id,full_name',
                'installments' => function($q) {
                    $q->orderBy('installment_number');
                }
            ]);

            // Access Control: Member only sees own loans
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            $loan = $query->findOrFail($id);

            // Add computed attributes
            $loan->total_amount = $loan->total_amount;
            $loan->total_interest = $loan->total_interest;
            $loan->remaining_principal = $loan->remaining_principal;
            $loan->paid_installments_count = $loan->paid_installments_count;
            $loan->pending_installments_count = $loan->pending_installments_count;
            $loan->overdue_installments_count = $loan->overdue_installments_count;

            return $this->successResponse(
                $loan,
                'Loan retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Loan not found or access denied', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve loan: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update the specified loan.
     */
    public function update(LoanRequest $request, int $id): JsonResponse
    {
        try {
            $loan = Loan::findOrFail($id);

            // Check if loan is still pending
            if (!$loan->isPending()) {
                return $this->errorResponse(
                    'Cannot update loan that is already ' . $loan->status,
                    400
                );
            }

            // Get interest rate
            $cashAccount = CashAccount::findOrFail($request->cash_account_id);
            $interestRate = $cashAccount->currentLoanRate();
            $interestPercentage = $interestRate ? $interestRate->rate_percentage : 12.0;

            // Recalculate installment
            $installmentAmount = Loan::calculateInstallment(
                $request->principal_amount,
                $interestPercentage,
                $request->tenure_months
            );

            $loan->update([
                'user_id' => $request->user_id,
                'cash_account_id' => $request->cash_account_id,
                'principal_amount' => $request->principal_amount,
                'interest_percentage' => $interestPercentage,
                'tenure_months' => $request->tenure_months,
                'installment_amount' => $installmentAmount,
                'application_date' => $request->application_date,
                'loan_purpose' => $request->loan_purpose,
                'document_path' => $request->document_path,
            ]);

            $loan->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name']);

            return $this->successResponse(
                $loan,
                'Loan updated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Loan not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update loan: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove the specified loan.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $loan = Loan::findOrFail($id);

            // Check if pending
            if (!$loan->isPending()) {
                return $this->errorResponse(
                    'Cannot delete loan that is already ' . $loan->status,
                    400
                );
            }

            $loan->delete();

            return $this->successResponse(
                null,
                'Loan deleted successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Loan not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to delete loan: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Approve or reject a loan.
     */
    public function approve(ApproveLoanRequest $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $loan = Loan::findOrFail($id);

            // Check if still pending
            if (!$loan->isPending()) {
                return $this->errorResponse(
                    'Loan is already ' . $loan->status,
                    400
                );
            }

            if ($request->status === 'approved') {
                // Approve and disburse
                $loan->update([
                    'status' => 'disbursed',
                    'approval_date' => now(),
                    'disbursement_date' => $request->disbursement_date,
                    'remaining_principal' => $loan->principal_amount,
                    'approved_by' => $user->id,
                ]);

                // Deduct from cash account balance
                $cashAccount = CashAccount::find($loan->cash_account_id);
                if ($cashAccount) {
                    $cashAccount->updateBalance($loan->principal_amount, 'subtract');
                }

                // Create installment schedule
                $loan->createInstallmentSchedule();

                // Update status to active
                $loan->update(['status' => 'active']);

                $message = 'Loan approved and disbursed successfully';
            } else {
                // Reject
                $loan->update([
                    'status' => 'rejected',
                    'rejection_reason' => $request->rejection_reason,
                    'approved_by' => $user->id,
                ]);

                $message = 'Loan rejected successfully';
            }

            $loan->load([
                'user:id,full_name,employee_id',
                'cashAccount:id,code,name',
                'approvedBy:id,full_name',
                'installments'
            ]);

            return $this->successResponse($loan, $message);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Loan not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to process approval: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get loan summary for a user.
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

            $targetUser = User::findOrFail($userId);

            $activeLoans = Loan::where('user_id', $userId)
                ->whereIn('status', ['disbursed', 'active'])
                ->get();

            $summary = [
                'user' => $targetUser->only(['id', 'full_name', 'employee_id']),
                'total_active_loans' => $activeLoans->count(),
                'total_principal_borrowed' => $activeLoans->sum('principal_amount'),
                'total_remaining_principal' => $activeLoans->sum(function($loan) {
                    return $loan->remaining_principal;
                }),
                'total_monthly_installment' => $activeLoans->sum('installment_amount'),
                'loan_history' => [
                    'completed' => Loan::where('user_id', $userId)->where('status', 'paid_off')->count(),
                    'rejected' => Loan::where('user_id', $userId)->where('status', 'rejected')->count(),
                ],
            ];

            return $this->successResponse(
                $summary,
                'Loan summary retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('User not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve summary: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get loan simulation/calculation.
     */
    public function simulate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'principal_amount' => 'required|numeric|min:100000',
                'tenure_months' => 'required|integer|min:6|max:60',
                'cash_account_id' => 'required|exists:cash_accounts,id',
            ]);

            // Get interest rate
            $cashAccount = CashAccount::findOrFail($request->cash_account_id);
            $interestRate = $cashAccount->currentLoanRate();
            $interestPercentage = $interestRate ? $interestRate->rate_percentage : 12.0;

            // Calculate installment
            $installmentAmount = Loan::calculateInstallment(
                $request->principal_amount,
                $interestPercentage,
                $request->tenure_months
            );

            $totalAmount = $installmentAmount * $request->tenure_months;
            $totalInterest = $totalAmount - $request->principal_amount;

            $simulation = [
                'principal_amount' => $request->principal_amount,
                'interest_percentage' => $interestPercentage,
                'tenure_months' => $request->tenure_months,
                'monthly_installment' => $installmentAmount,
                'total_amount' => $totalAmount,
                'total_interest' => $totalInterest,
                'effective_rate' => round(($totalInterest / $request->principal_amount) * 100, 2),
            ];

            return $this->successResponse(
                $simulation,
                'Loan simulation calculated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Cash account not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to calculate simulation: ' . $e->getMessage(),
                500
            );
        }
    }
}