<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Saving;
use App\Models\Loan;
use App\Models\Installment;
use App\Models\ServiceAllowance;
use App\Models\Gift;
use App\Models\CashAccount;
use App\Models\AccountingPeriod;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * Get admin dashboard overview.
     * 
     * @return JsonResponse
     */
    public function adminDashboard(): JsonResponse
    {
        try {
            $user = auth()->user();

            // Only admin/manager can access
            if (!$user->isAdmin() && !$user->isManager()) {
                return $this->errorResponse('Access denied', 403);
            }

            $dashboard = [
                'overview' => $this->getAdminOverview(),
                'financial_summary' => $this->getFinancialSummary(),
                'recent_activities' => $this->getRecentActivities(),
                'alerts' => $this->getAlerts(),
                'charts_data' => $this->getChartsData(),
            ];

            return $this->successResponse(
                $dashboard,
                'Admin dashboard retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve dashboard: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get member dashboard.
     * 
     * @return JsonResponse
     */
    public function memberDashboard(): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isMember()) {
                return $this->errorResponse('Member access only', 403);
            }

            $dashboard = [
                'profile' => [
                    'full_name' => $user->full_name,
                    'employee_id' => $user->employee_id,
                    'email' => $user->email,
                    'joined_at' => $user->joined_at?->format('d F Y'),
                    'membership_duration' => $user->membership_duration . ' bulan',
                    'status' => $user->membership_status,
                ],
                'financial_summary' => $user->getFinancialSummary(),
                'savings_summary' => $this->getMemberSavingsSummary($user->id),
                'loans_summary' => $this->getMemberLoansSummary($user->id),
                'recent_transactions' => $this->getMemberRecentTransactions($user->id),
                'upcoming_installments' => $this->getMemberUpcomingInstallments($user->id),
                'this_year_summary' => $this->getMemberYearSummary($user->id),
            ];

            return $this->successResponse(
                $dashboard,
                'Member dashboard retrieved successfully'
            );

        } catch (\Exception $e) {
            \Log::error('Member Dashboard Error', [
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            return $this->errorResponse(
                'Failed to retrieve dashboard: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get admin overview statistics.
     */
    private function getAdminOverview(): array
    {
        $currentYear = now()->year;
        $currentMonth = now()->month;

        return [
            'members' => [
                'total' => User::members()->count(),
                'active' => User::members()->active()->count(),
                'inactive' => User::members()->inactive()->count(),
                'suspended' => User::members()->suspended()->count(),
                'new_this_month' => User::members()
                    ->whereYear('joined_at', $currentYear)
                    ->whereMonth('joined_at', $currentMonth)
                    ->count(),
            ],
            'savings' => [
                'total_balance' => Saving::where('status', 'approved')->sum('final_amount'),
                'transactions_count' => Saving::where('status', 'approved')->count(),
                'pending_count' => Saving::where('status', 'pending')->count(),
                'this_month' => Saving::where('status', 'approved')
                    ->whereYear('transaction_date', $currentYear)
                    ->whereMonth('transaction_date', $currentMonth)
                    ->sum('final_amount'),
            ],
            'loans' => [
                'active_count' => Loan::whereIn('status', ['disbursed', 'active'])->count(),
                'total_principal' => Loan::whereIn('status', ['disbursed', 'active'])->sum('principal_amount'),
                'total_remaining' => Loan::whereIn('status', ['disbursed', 'active'])->sum('remaining_principal'),
                'pending_applications' => Loan::where('status', 'pending')->count(),
            ],
            'installments' => [
                'overdue_count' => Installment::where('status', 'overdue')->count(),
                'pending_count' => Installment::where('status', 'pending')->count(),
                'upcoming_7days' => Installment::where('status', 'pending')
                    ->whereBetween('due_date', [now(), now()->addDays(7)])
                    ->count(),
            ],
            'cash_accounts' => [
                'total_balance' => CashAccount::where('is_active', true)->sum('current_balance'),
                'active_accounts' => CashAccount::where('is_active', true)->count(),
            ],
        ];
    }

    /**
     * Get financial summary.
     */
    private function getFinancialSummary(): array
    {
        $currentYear = now()->year;

        return [
            'year' => $currentYear,
            'savings_collected' => Saving::where('status', 'approved')
                ->whereYear('transaction_date', $currentYear)
                ->sum('final_amount'),
            'loans_disbursed' => Loan::whereIn('status', ['disbursed', 'active', 'paid_off'])
                ->whereYear('disbursement_date', $currentYear)
                ->sum('principal_amount'),
            'installments_collected' => Installment::whereIn('status', ['auto_paid', 'paid'])
                ->whereYear('payment_date', $currentYear)
                ->sum('total_amount'),
            'service_allowances_received' => ServiceAllowance::whereIn('status', ['processed', 'paid'])
                ->where('period_year', $currentYear)
                ->sum('received_amount'),  // ✅
            'gifts_distributed' => Gift::where('status', 'distributed')
                ->whereYear('distribution_date', $currentYear)
                ->sum('gift_value'),
        ];
    }

    /**
     * Get recent activities.
     */
    private function getRecentActivities(): array
    {
        $activities = [];

        // Recent savings (last 10)
        $recentSavings = Saving::with('user:id,full_name')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function($saving) {
                return [
                    'type' => 'saving',
                    'title' => 'Simpanan ' . $saving->type_name,
                    'description' => $saving->user->full_name . ' - Rp ' . number_format($saving->final_amount, 0, ',', '.'),
                    'date' => $saving->transaction_date->format('d M Y'),
                    'status' => $saving->status,
                    'amount' => $saving->final_amount,
                ];
            });

        // Recent loans (last 5)
        $recentLoans = Loan::with('user:id,full_name')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function($loan) {
                return [
                    'type' => 'loan',
                    'title' => 'Pinjaman ' . $loan->loan_number,
                    'description' => $loan->user->full_name . ' - Rp ' . number_format($loan->principal_amount, 0, ',', '.'),
                    'date' => $loan->application_date->format('d M Y'),
                    'status' => $loan->status,
                    'amount' => $loan->principal_amount,
                ];
            });

        // Merge and sort
        $activities = $recentSavings->concat($recentLoans)
            ->sortByDesc('date')
            ->take(10)
            ->values()
            ->toArray();

        return $activities;
    }

    /**
     * Get alerts and notifications.
     */
    private function getAlerts(): array
    {
        $alerts = [];

        // Overdue installments alert
        $overdueCount = Installment::where('status', 'overdue')->count();
        if ($overdueCount > 0) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'Cicilan Terlambat',
                'message' => "Ada {$overdueCount} cicilan yang terlambat",
                'action' => 'Lihat Detail',
                'link' => '/installments/overdue',
            ];
        }

        // Pending loan applications
        $pendingLoans = Loan::where('status', 'pending')->count();
        if ($pendingLoans > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Pengajuan Pinjaman',
                'message' => "Ada {$pendingLoans} pengajuan pinjaman menunggu persetujuan",
                'action' => 'Review Sekarang',
                'link' => '/loans?status=pending',
            ];
        }

        // Pending savings
        $pendingSavings = Saving::where('status', 'pending')->count();
        if ($pendingSavings > 0) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Simpanan Pending',
                'message' => "Ada {$pendingSavings} transaksi simpanan menunggu persetujuan",
                'action' => 'Lihat',
                'link' => '/savings?status=pending',
            ];
        }

        // Upcoming installments (next 7 days)
        $upcoming = Installment::where('status', 'pending')
            ->whereBetween('due_date', [now(), now()->addDays(7)])
            ->count();
        if ($upcoming > 0) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Cicilan Mendatang',
                'message' => "{$upcoming} cicilan jatuh tempo dalam 7 hari ke depan",
                'action' => 'Lihat',
                'link' => '/installments/upcoming',
            ];
        }

        return $alerts;
    }

    /**
     * Get charts data for admin dashboard.
     */
    private function getChartsData(): array
    {
        $currentYear = now()->year;

        // Savings per month (this year)
        $savingsPerMonth = [];
        for ($month = 1; $month <= 12; $month++) {
            $savingsPerMonth[] = [
                'month' => Carbon::create($currentYear, $month, 1)->format('M'),
                'amount' => Saving::where('status', 'approved')
                    ->whereYear('transaction_date', $currentYear)
                    ->whereMonth('transaction_date', $month)
                    ->sum('final_amount'),
            ];
        }

        // Loans vs Savings comparison
        $loansVsSavings = [
            'labels' => ['Simpanan', 'Pinjaman'],
            'data' => [
                Saving::where('status', 'approved')->sum('final_amount'),
                Loan::whereIn('status', ['disbursed', 'active'])->sum('principal_amount'),
            ],
        ];

        // Savings by type
        $savingsByType = [
            'labels' => ['Pokok', 'Wajib', 'Sukarela', 'Hari Raya'],
            'data' => [
                Saving::where('savings_type', 'principal')->where('status', 'approved')->sum('final_amount'),
                Saving::where('savings_type', 'mandatory')->where('status', 'approved')->sum('final_amount'),
                Saving::where('savings_type', 'voluntary')->where('status', 'approved')->sum('final_amount'),
                Saving::where('savings_type', 'holiday')->where('status', 'approved')->sum('final_amount'),
            ],
        ];

        // Member growth (last 6 months)
        $memberGrowth = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $memberGrowth[] = [
                'month' => $date->format('M Y'),
                'count' => User::members()
                    ->where('joined_at', '<=', $date->endOfMonth())
                    ->count(),
            ];
        }

        return [
            'savings_per_month' => $savingsPerMonth,
            'loans_vs_savings' => $loansVsSavings,
            'savings_by_type' => $savingsByType,
            'member_growth' => $memberGrowth,
        ];
    }

    /**
     * Get member savings summary.
     */
    private function getMemberSavingsSummary(int $userId): array
    {
        return [
            'total' => Saving::getTotalForUser($userId),
            'by_type' => [
                'principal' => [
                    'amount' => Saving::getTotalByType($userId, 'principal'),
                    'name' => 'Simpanan Pokok',
                ],
                'mandatory' => [
                    'amount' => Saving::getTotalByType($userId, 'mandatory'),
                    'name' => 'Simpanan Wajib',
                ],
                'voluntary' => [
                    'amount' => Saving::getTotalByType($userId, 'voluntary'),
                    'name' => 'Simpanan Sukarela',
                ],
                'holiday' => [
                    'amount' => Saving::getTotalByType($userId, 'holiday'),
                    'name' => 'Simpanan Hari Raya',
                ],
            ],
            'transaction_count' => Saving::where('user_id', $userId)
                ->where('status', 'approved')
                ->count(),
        ];
    }

    /**
     * Get member loans summary.
     */
    private function getMemberLoansSummary(int $userId): array
    {
        $activeLoans = Loan::where('user_id', $userId)
            ->whereIn('status', ['disbursed', 'active'])
            ->get();

        return [
            'active_count' => $activeLoans->count(),
            'total_borrowed' => $activeLoans->sum('principal_amount'),
            'total_remaining' => $activeLoans->sum('remaining_principal'),
            'monthly_installment' => $activeLoans->sum('installment_amount'),
            'completed_count' => Loan::where('user_id', $userId)
                ->where('status', 'paid_off')
                ->count(),
        ];
    }

    /**
     * Get member recent transactions.
     */
    private function getMemberRecentTransactions(int $userId): array
    {
        // Get savings transactions
        $savings = Saving::where('user_id', $userId)
            ->with('cashAccount:id,code,name')
            ->latest('transaction_date')
            ->limit(5)
            ->get()
            ->map(function($saving) {
                // ✅ FIX: Add null check for cash_account
                $accountName = $saving->cashAccount 
                    ? $saving->cashAccount->name 
                    : 'Tidak ada kas';
                
                return [
                    'type' => 'saving',
                    'title' => $saving->type_name,
                    'description' => $accountName,
                    'amount' => $saving->final_amount,
                    'date' => $saving->transaction_date->format('d M Y'),
                    'status' => $saving->status,
                ];
            });

        // Get installment transactions
        $installments = Installment::whereHas('loan', function($q) use ($userId) {
            $q->where('user_id', $userId);
        })
        ->with('loan:id,loan_number')
        ->latest('payment_date')
        ->limit(5)
        ->get()
        ->map(function($installment) {
            // ✅ FIX: Add null check for loan
            $loanNumber = $installment->loan 
                ? $installment->loan->loan_number 
                : 'Unknown';
            
            return [
                'type' => 'installment',
                'title' => 'Cicilan ' . $loanNumber,
                'description' => 'Angsuran ke-' . $installment->installment_number,
                'amount' => $installment->total_amount,
                'date' => $installment->payment_date?->format('d M Y') ?? $installment->due_date->format('d M Y'),
                'status' => $installment->status,
            ];
        });

        return $savings->concat($installments)
            ->sortByDesc('date')
            ->take(10)
            ->values()
            ->toArray();
    }

    /**
     * Get member upcoming installments.
     */
    private function getMemberUpcomingInstallments(int $userId): array
    {
        return Installment::whereHas('loan', function($q) use ($userId) {
            $q->where('user_id', $userId);
        })
        ->with('loan:id,loan_number')
        ->whereIn('status', ['pending', 'overdue'])
        ->orderBy('due_date')
        ->limit(5)
        ->get()
        ->map(function($installment) {
            return [
                'id' => $installment->id,
                'loan_number' => $installment->loan->loan_number,
                'installment_number' => $installment->installment_number,
                'amount' => $installment->total_amount,
                'due_date' => $installment->due_date->format('d M Y'),
                'status' => $installment->status,
                'days_until_due' => $installment->status === 'pending' ? $installment->days_until_due : null,
                'days_overdue' => $installment->status === 'overdue' ? $installment->days_overdue : null,
            ];
        })
        ->toArray();
    }

    /**
     * Get member year summary.
     */
    private function getMemberYearSummary(int $userId): array
    {
        $currentYear = now()->year;

        return [
            'year' => $currentYear,
            'total_savings' => Saving::where('user_id', $userId)
                ->where('status', 'approved')
                ->whereYear('transaction_date', $currentYear)
                ->sum('final_amount'),
            'total_installments_paid' => Installment::whereHas('loan', function($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->whereIn('status', ['auto_paid', 'paid'])
            ->whereYear('payment_date', $currentYear)
            ->sum('total_amount'),
            'service_allowance_received' => ServiceAllowance::getMemberTotalForYear($userId, $currentYear),
            'gifts_received' => Gift::getMemberTotalForYear($userId, $currentYear),
        ];
    }

    /**
     * Get quick statistics.
     */
    public function quickStats(): JsonResponse
    {
        try {
            $user = auth()->user();

            if ($user->isAdmin() || $user->isManager()) {
                $stats = [
                    'total_members' => User::members()->active()->count(),
                    'total_savings' => Saving::where('status', 'approved')->sum('final_amount'),
                    'active_loans' => Loan::whereIn('status', ['disbursed', 'active'])->count(),
                    'overdue_installments' => Installment::where('status', 'overdue')->count(),
                ];
            } else {
                $stats = [
                    'total_savings' => Saving::getTotalForUser($user->id),
                    'active_loans' => Loan::where('user_id', $user->id)
                        ->whereIn('status', ['disbursed', 'active'])
                        ->count(),
                    'monthly_installment' => $user->monthly_installment,
                    'upcoming_installments' => Installment::whereHas('loan', function($q) use ($user) {
                        $q->where('user_id', $user->id);
                    })
                    ->whereBetween('due_date', [now(), now()->addDays(7)])
                    ->count(),
                ];
            }

            return $this->successResponse($stats, 'Quick stats retrieved successfully');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve stats: ' . $e->getMessage(),
                500
            );
        }
    }

    // =====================================================
    // ADD THIS METHOD TO DashboardController.php
    // =====================================================

    /**
     * Get manager dashboard - shows cash accounts managed by this manager.
     * 
     * Business Logic:
     * - Manager sees only cash accounts they manage
     * - Shows savings, loans, and transactions for their cash accounts
     * - Summary statistics for managed accounts
     * 
     * @return JsonResponse
     */
    public function managerDashboard(): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Manager only
            if (!$user->isManager()) {
                return $this->errorResponse('Manager access only', 403);
            }

            // Get cash accounts managed by this manager
            $managedAccounts = $user->managedCashAccounts()
                ->with(['currentLoanRate', 'currentSavingsRate'])
                ->get();

            if ($managedAccounts->isEmpty()) {
                return $this->successResponse([
                    'message' => 'No cash accounts assigned to you yet',
                    'managed_accounts' => [],
                    'summary' => [
                        'total_accounts' => 0,
                        'total_balance' => 0,
                        'total_savings' => 0,
                        'total_loans_disbursed' => 0,
                        'active_loans' => 0,
                    ],
                ], 'Manager dashboard retrieved successfully');
            }

            $cashAccountIds = $managedAccounts->pluck('id')->toArray();

            // Build dashboard data
            $dashboard = [
                'manager_info' => [
                    'full_name' => $user->full_name,
                    'employee_id' => $user->employee_id,
                    'email' => $user->email,
                    'managed_accounts_count' => $managedAccounts->count(),
                ],
                'managed_accounts' => $this->getManagerAccountsSummary($managedAccounts),
                'summary' => $this->getManagerSummary($cashAccountIds),
                'recent_transactions' => $this->getManagerRecentTransactions($cashAccountIds),
                'pending_approvals' => $this->getManagerPendingApprovals($cashAccountIds),
                'alerts' => $this->getManagerAlerts($cashAccountIds),
                'statistics' => $this->getManagerStatistics($cashAccountIds),
            ];

            return $this->successResponse(
                $dashboard,
                'Manager dashboard retrieved successfully'
            );

        } catch (\Exception $e) {
            \Log::error('Manager Dashboard Error', [
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->errorResponse(
                'Failed to retrieve dashboard: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get summary of managed accounts.
     */
    private function getManagerAccountsSummary($managedAccounts): array
    {
        return $managedAccounts->map(function($account) {
            // Get counts for this account
            $savingsCount = Saving::where('cash_account_id', $account->id)
                ->where('status', 'approved')
                ->count();
            
            $loansCount = Loan::where('cash_account_id', $account->id)
                ->whereIn('status', ['disbursed', 'active'])
                ->count();

            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'description' => $account->description,
                'type' => $account->type,
                'current_balance' => (float) $account->current_balance,
                'is_active' => (bool) $account->is_active,
                'loan_rate' => $account->currentLoanRate 
                    ? (float) $account->currentLoanRate->rate_percentage 
                    : null,
                'savings_rate' => $account->currentSavingsRate 
                    ? (float) $account->currentSavingsRate->rate_percentage 
                    : null,
                'savings_count' => $savingsCount,
                'active_loans_count' => $loansCount,
            ];
        })->toArray();
    }

    /**
     * Get manager's overall summary.
     */
    private function getManagerSummary(array $cashAccountIds): array
    {
        if (empty($cashAccountIds)) {
            return [
                'total_accounts' => 0,
                'total_balance' => 0,
                'total_savings' => 0,
                'total_loans_disbursed' => 0,
                'active_loans' => 0,
                'pending_savings' => 0,
                'pending_loans' => 0,
            ];
        }

        return [
            'total_accounts' => count($cashAccountIds),
            'total_balance' => (float) CashAccount::whereIn('id', $cashAccountIds)
                ->sum('current_balance'),
            'total_savings' => (float) Saving::whereIn('cash_account_id', $cashAccountIds)
                ->where('status', 'approved')
                ->sum('final_amount'),
            'total_loans_disbursed' => (float) Loan::whereIn('cash_account_id', $cashAccountIds)
                ->whereIn('status', ['disbursed', 'active', 'paid_off'])
                ->sum('principal_amount'),
            'active_loans' => Loan::whereIn('cash_account_id', $cashAccountIds)
                ->whereIn('status', ['disbursed', 'active'])
                ->count(),
            'pending_savings' => Saving::whereIn('cash_account_id', $cashAccountIds)
                ->where('status', 'pending')
                ->count(),
            'pending_loans' => Loan::whereIn('cash_account_id', $cashAccountIds)
                ->where('status', 'pending')
                ->count(),
        ];
    }

    /**
     * Get recent transactions for managed accounts.
     */
    private function getManagerRecentTransactions(array $cashAccountIds): array
    {
        if (empty($cashAccountIds)) {
            return [];
        }

        // Get recent savings
        $recentSavings = Saving::whereIn('cash_account_id', $cashAccountIds)
            ->with(['user:id,full_name,employee_id', 'cashAccount:id,code,name'])
            ->latest('transaction_date')
            ->limit(10)
            ->get()
            ->map(function($saving) {
                return [
                    'type' => 'saving',
                    'id' => $saving->id,
                    'title' => 'Simpanan ' . $saving->type_name,
                    'description' => ($saving->user ? $saving->user->full_name : 'Unknown') . 
                        ' → ' . 
                        ($saving->cashAccount ? $saving->cashAccount->name : 'Unknown'),
                    'amount' => (float) $saving->final_amount,
                    'date' => $saving->transaction_date->format('Y-m-d H:i:s'),
                    'status' => $saving->status,
                    'cash_account' => $saving->cashAccount ? [
                        'id' => $saving->cashAccount->id,
                        'code' => $saving->cashAccount->code,
                        'name' => $saving->cashAccount->name,
                    ] : null,
                ];
            });

        // Get recent loans
        $recentLoans = Loan::whereIn('cash_account_id', $cashAccountIds)
            ->with(['user:id,full_name,employee_id', 'cashAccount:id,code,name'])
            ->latest('application_date')
            ->limit(10)
            ->get()
            ->map(function($loan) {
                return [
                    'type' => 'loan',
                    'id' => $loan->id,
                    'title' => 'Pinjaman ' . $loan->loan_number,
                    'description' => ($loan->user ? $loan->user->full_name : 'Unknown') . 
                        ' → ' . 
                        ($loan->cashAccount ? $loan->cashAccount->name : 'Unknown'),
                    'amount' => (float) $loan->principal_amount,
                    'date' => $loan->application_date->format('Y-m-d H:i:s'),
                    'status' => $loan->status,
                    'cash_account' => $loan->cashAccount ? [
                        'id' => $loan->cashAccount->id,
                        'code' => $loan->cashAccount->code,
                        'name' => $loan->cashAccount->name,
                    ] : null,
                ];
            });

        // Merge and sort by date
        return $recentSavings->concat($recentLoans)
            ->sortByDesc('date')
            ->take(15)
            ->values()
            ->toArray();
    }

    /**
     * Get pending items that need manager approval.
     */
    private function getManagerPendingApprovals(array $cashAccountIds): array
    {
        if (empty($cashAccountIds)) {
            return [
                'pending_savings' => [],
                'pending_loans' => [],
            ];
        }

        // Pending savings
        $pendingSavings = Saving::whereIn('cash_account_id', $cashAccountIds)
            ->where('status', 'pending')
            ->with(['user:id,full_name,employee_id', 'cashAccount:id,code,name'])
            ->latest('transaction_date')
            ->limit(10)
            ->get()
            ->map(function($saving) {
                return [
                    'id' => $saving->id,
                    'type' => $saving->type_name,
                    'member_name' => $saving->user ? $saving->user->full_name : 'Unknown',
                    'employee_id' => $saving->user ? $saving->user->employee_id : 'N/A',
                    'amount' => (float) $saving->final_amount,
                    'date' => $saving->transaction_date->format('Y-m-d'),
                    'cash_account' => $saving->cashAccount ? $saving->cashAccount->name : 'Unknown',
                ];
            });

        // Pending loans
        $pendingLoans = Loan::whereIn('cash_account_id', $cashAccountIds)
            ->where('status', 'pending')
            ->with(['user:id,full_name,employee_id', 'cashAccount:id,code,name'])
            ->latest('application_date')
            ->limit(10)
            ->get()
            ->map(function($loan) {
                return [
                    'id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'member_name' => $loan->user ? $loan->user->full_name : 'Unknown',
                    'employee_id' => $loan->user ? $loan->user->employee_id : 'N/A',
                    'amount' => (float) $loan->principal_amount,
                    'tenure_months' => $loan->tenure_months,
                    'date' => $loan->application_date->format('Y-m-d'),
                    'cash_account' => $loan->cashAccount ? $loan->cashAccount->name : 'Unknown',
                ];
            });

        return [
            'pending_savings' => $pendingSavings->toArray(),
            'pending_loans' => $pendingLoans->toArray(),
        ];
    }

    /**
     * Get alerts for manager.
     */
    private function getManagerAlerts(array $cashAccountIds): array
    {
        if (empty($cashAccountIds)) {
            return [];
        }

        $alerts = [];

        // Pending approvals alert
        $pendingSavingsCount = Saving::whereIn('cash_account_id', $cashAccountIds)
            ->where('status', 'pending')
            ->count();
        
        $pendingLoansCount = Loan::whereIn('cash_account_id', $cashAccountIds)
            ->where('status', 'pending')
            ->count();

        if ($pendingSavingsCount > 0) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Simpanan Pending',
                'message' => "{$pendingSavingsCount} transaksi simpanan menunggu persetujuan",
                'action' => 'Review',
                'link' => '/savings?status=pending',
            ];
        }

        if ($pendingLoansCount > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Pengajuan Pinjaman',
                'message' => "{$pendingLoansCount} pengajuan pinjaman menunggu persetujuan",
                'action' => 'Review',
                'link' => '/loans?status=pending',
            ];
        }

        // Overdue installments in managed accounts
        $overdueCount = Installment::whereHas('loan', function($q) use ($cashAccountIds) {
            $q->whereIn('cash_account_id', $cashAccountIds);
        })
        ->where('status', 'overdue')
        ->count();

        if ($overdueCount > 0) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'Cicilan Terlambat',
                'message' => "{$overdueCount} cicilan terlambat di kas yang Anda kelola",
                'action' => 'Lihat',
                'link' => '/installments/overdue',
            ];
        }

        // Low balance warning
        $lowBalanceAccounts = CashAccount::whereIn('id', $cashAccountIds)
            ->where('current_balance', '<', 1000000) // Less than 1M
            ->where('is_active', true)
            ->get();

        if ($lowBalanceAccounts->count() > 0) {
            $accountNames = $lowBalanceAccounts->pluck('name')->join(', ');
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Saldo Kas Rendah',
                'message' => "Saldo rendah pada: {$accountNames}",
                'action' => 'Cek Saldo',
                'link' => '/cash-accounts',
            ];
        }

        return $alerts;
    }

    /**
     * Get statistics for managed accounts.
     */
    private function getManagerStatistics(array $cashAccountIds): array
    {
        if (empty($cashAccountIds)) {
            return [
                'this_month' => [
                    'savings_collected' => 0,
                    'loans_disbursed' => 0,
                    'installments_collected' => 0,
                ],
                'this_year' => [
                    'savings_collected' => 0,
                    'loans_disbursed' => 0,
                    'installments_collected' => 0,
                ],
            ];
        }

        $currentYear = now()->year;
        $currentMonth = now()->month;

        return [
            'this_month' => [
                'savings_collected' => (float) Saving::whereIn('cash_account_id', $cashAccountIds)
                    ->where('status', 'approved')
                    ->whereYear('transaction_date', $currentYear)
                    ->whereMonth('transaction_date', $currentMonth)
                    ->sum('final_amount'),
                'loans_disbursed' => (float) Loan::whereIn('cash_account_id', $cashAccountIds)
                    ->whereIn('status', ['disbursed', 'active'])
                    ->whereYear('disbursement_date', $currentYear)
                    ->whereMonth('disbursement_date', $currentMonth)
                    ->sum('principal_amount'),
                'installments_collected' => (float) Installment::whereHas('loan', function($q) use ($cashAccountIds) {
                    $q->whereIn('cash_account_id', $cashAccountIds);
                })
                ->whereIn('status', ['auto_paid', 'paid'])
                ->whereYear('payment_date', $currentYear)
                ->whereMonth('payment_date', $currentMonth)
                ->sum('total_amount'),
            ],
            'this_year' => [
                'savings_collected' => (float) Saving::whereIn('cash_account_id', $cashAccountIds)
                    ->where('status', 'approved')
                    ->whereYear('transaction_date', $currentYear)
                    ->sum('final_amount'),
                'loans_disbursed' => (float) Loan::whereIn('cash_account_id', $cashAccountIds)
                    ->whereIn('status', ['disbursed', 'active', 'paid_off'])
                    ->whereYear('disbursement_date', $currentYear)
                    ->sum('principal_amount'),
                'installments_collected' => (float) Installment::whereHas('loan', function($q) use ($cashAccountIds) {
                    $q->whereIn('cash_account_id', $cashAccountIds);
                })
                ->whereIn('status', ['auto_paid', 'paid'])
                ->whereYear('payment_date', $currentYear)
                ->sum('total_amount'),
            ],
        ];
    }
}