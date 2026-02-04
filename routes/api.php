<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health check
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is running',
        'timestamp' => now()->toDateTimeString()
    ]);
});

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    // Public routes (no authentication required)
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    
    // Protected routes (authentication required)
    Route::middleware(['jwt.auth'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

/*
|--------------------------------------------------------------------------
| Chart of Accounts Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    // Chart of Accounts
    Route::prefix('chart-of-accounts')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\ChartOfAccountController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\ChartOfAccountController::class, 'store'])
            ->middleware('role:admin,manager');
        Route::get('/category/{category}', [App\Http\Controllers\Api\ChartOfAccountController::class, 'getByCategory']);
        Route::get('/summary', [App\Http\Controllers\Api\ChartOfAccountController::class, 'getCategorySummary']);
        Route::get('/{id}', [App\Http\Controllers\Api\ChartOfAccountController::class, 'show']);
        Route::put('/{id}', [App\Http\Controllers\Api\ChartOfAccountController::class, 'update'])
            ->middleware('role:admin,manager');
        Route::delete('/{id}', [App\Http\Controllers\Api\ChartOfAccountController::class, 'destroy'])
            ->middleware('role:admin');
    });
});

/*
|--------------------------------------------------------------------------
| Cash Accounts Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    // Cash Accounts - Main CRUD
    Route::prefix('cash-accounts')->group(function () {
        
        // ==================== READ OPERATIONS ====================
        Route::get('/', [App\Http\Controllers\Api\CashAccountController::class, 'index'])
            ->middleware('role:admin,manager,anggota');
        
        Route::get('/summary', [App\Http\Controllers\Api\CashAccountController::class, 'getSummary'])
            ->middleware('role:admin,manager');
        
        Route::get('/{id}', [App\Http\Controllers\Api\CashAccountController::class, 'show'])
            ->middleware('role:admin,manager');
        
        // ==================== WRITE OPERATIONS ====================
        Route::post('/', [App\Http\Controllers\Api\CashAccountController::class, 'store'])
            ->middleware('role:admin,manager');
        
        Route::put('/{id}', [App\Http\Controllers\Api\CashAccountController::class, 'update'])
            ->middleware('role:admin');
        
        Route::delete('/{id}', [App\Http\Controllers\Api\CashAccountController::class, 'destroy'])
            ->middleware('role:admin');
        
        // ==================== MANAGER ASSIGNMENT ====================
        Route::prefix('{cashAccountId}/managers')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\CashAccountManagerController::class, 'index'])
                ->middleware('role:admin,manager');
            
            Route::post('/', [App\Http\Controllers\Api\CashAccountManagerController::class, 'store'])
                ->middleware('role:admin');
            
            Route::delete('/{managerId}', [App\Http\Controllers\Api\CashAccountManagerController::class, 'destroy'])
                ->middleware('role:admin');
        });
        
        Route::get('/managers/available', [App\Http\Controllers\Api\CashAccountManagerController::class, 'getAvailableManagers'])
            ->middleware('role:admin');
        
        // ==================== INTEREST RATES ====================
        Route::prefix('{cashAccountId}/interest-rates')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\InterestRateController::class, 'index'])
                ->middleware('role:admin,manager');
            
            Route::post('/', [App\Http\Controllers\Api\InterestRateController::class, 'store'])
                ->middleware('role:admin,manager');
        });
    });
    
    // Interest Rates - Direct access
    Route::prefix('interest-rates')->group(function () {
        Route::get('/current', [App\Http\Controllers\Api\InterestRateController::class, 'getCurrentRates'])
            ->middleware('role:admin,manager');
        
        Route::put('/{id}', [App\Http\Controllers\Api\InterestRateController::class, 'update'])
            ->middleware('role:admin,manager');
        
        Route::delete('/{id}', [App\Http\Controllers\Api\InterestRateController::class, 'destroy'])
            ->middleware('role:admin');
    });
    
    // Manager's Dashboard
    Route::get('/managers/{managerId}/cash-accounts', 
        [App\Http\Controllers\Api\CashAccountManagerController::class, 'getManagedAccounts'])
        ->middleware('role:admin,manager');
});

/*
|--------------------------------------------------------------------------
| Accounting Periods Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    Route::prefix('accounting-periods')->group(function () {
        // Read operations
        Route::get('/', [App\Http\Controllers\Api\AccountingPeriodController::class, 'index']);
        Route::get('/active', [App\Http\Controllers\Api\AccountingPeriodController::class, 'getActive']);
        Route::get('/summary', [App\Http\Controllers\Api\AccountingPeriodController::class, 'getSummary']);
        Route::get('/{id}', [App\Http\Controllers\Api\AccountingPeriodController::class, 'show']);
        
        // Write operations (Admin only)
        Route::post('/', [App\Http\Controllers\Api\AccountingPeriodController::class, 'store'])
            ->middleware('role:admin');
        Route::put('/{id}', [App\Http\Controllers\Api\AccountingPeriodController::class, 'update'])
            ->middleware('role:admin');
        Route::delete('/{id}', [App\Http\Controllers\Api\AccountingPeriodController::class, 'destroy'])
            ->middleware('role:admin');
        
        // Period closing
        Route::post('/{id}/close', [App\Http\Controllers\Api\AccountingPeriodController::class, 'close'])
            ->middleware('role:admin');
        Route::post('/{id}/reopen', [App\Http\Controllers\Api\AccountingPeriodController::class, 'reopen'])
            ->middleware('role:admin');
    });
});

/*
|--------------------------------------------------------------------------
| Saving Types Routes (NEW!)
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    Route::prefix('saving-types')->group(function () {
        // Read operations (All authenticated users)
        Route::get('/', [App\Http\Controllers\Api\SavingTypeController::class, 'index']);
        Route::get('/defaults', [App\Http\Controllers\Api\SavingTypeController::class, 'defaults']);
        Route::get('/mandatory', [App\Http\Controllers\Api\SavingTypeController::class, 'mandatory']);
        Route::get('/optional', [App\Http\Controllers\Api\SavingTypeController::class, 'optional']);
        Route::get('/{id}', [App\Http\Controllers\Api\SavingTypeController::class, 'show']);
        
        // Write operations (Admin only)
        Route::post('/', [App\Http\Controllers\Api\SavingTypeController::class, 'store'])
            ->middleware('role:admin');
        Route::put('/{id}', [App\Http\Controllers\Api\SavingTypeController::class, 'update'])
            ->middleware('role:admin');
        Route::delete('/{id}', [App\Http\Controllers\Api\SavingTypeController::class, 'destroy'])
            ->middleware('role:admin');
    });
});

/*
|--------------------------------------------------------------------------
| Savings Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'activity.log'])->group(function () {
    
    Route::prefix('savings')->group(function () {
        // Read operations
        Route::get('/', [App\Http\Controllers\Api\SavingController::class, 'index']);
        Route::get('/summary', [App\Http\Controllers\Api\SavingController::class, 'getSummary']);
        Route::get('/type/{type}', [App\Http\Controllers\Api\SavingController::class, 'getByType']);
        Route::get('/{id}', [App\Http\Controllers\Api\SavingController::class, 'show']);
        
        // Write operations (Admin & Manager only)
        Route::post('/', [App\Http\Controllers\Api\SavingController::class, 'store'])
            ->middleware('role:admin,manager');
        Route::put('/{id}', [App\Http\Controllers\Api\SavingController::class, 'update'])
            ->middleware('role:admin,manager');
        Route::delete('/{id}', [App\Http\Controllers\Api\SavingController::class, 'destroy'])
            ->middleware('role:admin,manager');
        
        // Approval
        Route::post('/{id}/approve', [App\Http\Controllers\Api\SavingController::class, 'approve'])
            ->middleware('role:admin,manager');
    });
});

/*
|--------------------------------------------------------------------------
| Loans Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'activity.log'])->group(function () {
    
    Route::prefix('loans')->group(function () {
        
        // Check eligibility
        Route::post('/check-eligibility', [App\Http\Controllers\Api\LoanController::class, 'checkEligibility']);
        
        // Loan simulation
        Route::post('/simulate', [App\Http\Controllers\Api\LoanController::class, 'simulate']);
        
        // Read operations
        Route::get('/', [App\Http\Controllers\Api\LoanController::class, 'index']);
        Route::get('/summary', [App\Http\Controllers\Api\LoanController::class, 'getSummary']);
        Route::get('/{id}', [App\Http\Controllers\Api\LoanController::class, 'show']);
        
        // Write operations
        Route::post('/', [App\Http\Controllers\Api\LoanController::class, 'store'])
            ->middleware('role:admin,manager,anggota');
        
        Route::put('/{id}', [App\Http\Controllers\Api\LoanController::class, 'update'])
            ->middleware('role:admin,manager');
        
        Route::delete('/{id}', [App\Http\Controllers\Api\LoanController::class, 'destroy'])
            ->middleware('role:admin,manager');
        
        // Approval
        Route::post('/{id}/approve', [App\Http\Controllers\Api\LoanController::class, 'approve'])
            ->middleware('role:admin,manager');
        
        // NEW: Early Settlement
        Route::get('/{id}/early-settlement/preview', [App\Http\Controllers\Api\LoanController::class, 'earlySettlementPreview'])
            ->middleware('role:admin,manager');
        Route::post('/{id}/early-settlement', [App\Http\Controllers\Api\LoanController::class, 'earlySettlement'])
            ->middleware('role:admin,manager');
        
        // Installments for specific loan
        Route::get('/{loanId}/installments', [App\Http\Controllers\Api\InstallmentController::class, 'index']);
        Route::get('/{loanId}/schedule', [App\Http\Controllers\Api\InstallmentController::class, 'schedule']);
    });
    
    // Installments
    Route::prefix('installments')->group(function () {
        Route::get('/upcoming', [App\Http\Controllers\Api\InstallmentController::class, 'upcoming']);
        Route::get('/overdue', [App\Http\Controllers\Api\InstallmentController::class, 'overdue']);
        Route::get('/{id}', [App\Http\Controllers\Api\InstallmentController::class, 'show']);
        Route::post('/{id}/pay', [App\Http\Controllers\Api\InstallmentController::class, 'pay'])
            ->middleware('role:admin,manager');
    });
});

/*
|--------------------------------------------------------------------------
| Member Resignation Routes (NEW!)
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'activity.log'])->group(function () {
    
    Route::prefix('resignations')->group(function () {
        // Read operations
        Route::get('/', [App\Http\Controllers\Api\MemberResignationController::class, 'index'])
            ->middleware('role:admin,manager');
        Route::get('/statistics', [App\Http\Controllers\Api\MemberResignationController::class, 'statistics'])
            ->middleware('role:admin,manager');
        Route::get('/{id}', [App\Http\Controllers\Api\MemberResignationController::class, 'show'])
            ->middleware('role:admin,manager');
        
        // Create resignation request
        Route::post('/', [App\Http\Controllers\Api\MemberResignationController::class, 'store'])
            ->middleware('role:admin,manager,anggota');
        
        // Process (approve/reject)
        Route::post('/{id}/process', [App\Http\Controllers\Api\MemberResignationController::class, 'process'])
            ->middleware('role:admin,manager');
        
        // Process withdrawal after approval
        Route::post('/{id}/withdraw', [App\Http\Controllers\Api\MemberWithdrawalController::class, 'process'])
            ->middleware('role:admin,manager');
    });
    
    // Member resignation history
    Route::get('/members/{userId}/resignations', [App\Http\Controllers\Api\MemberResignationController::class, 'memberHistory'])
        ->middleware('role:admin,manager');
});

/*
|--------------------------------------------------------------------------
| Member Withdrawal Routes (NEW!)
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    Route::prefix('withdrawals')->group(function () {
        // Read operations (Admin & Manager only)
        Route::get('/', [App\Http\Controllers\Api\MemberWithdrawalController::class, 'index'])
            ->middleware('role:admin,manager');
        Route::get('/statistics', [App\Http\Controllers\Api\MemberWithdrawalController::class, 'statistics'])
            ->middleware('role:admin,manager');
        Route::get('/{id}', [App\Http\Controllers\Api\MemberWithdrawalController::class, 'show'])
            ->middleware('role:admin,manager');
    });
});

/*
|--------------------------------------------------------------------------
| Cash Transfer Routes (NEW!)
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'activity.log'])->group(function () {
    
    Route::prefix('cash-transfers')->group(function () {
        // Read operations
        Route::get('/', [App\Http\Controllers\Api\CashTransferController::class, 'index'])
            ->middleware('role:admin,manager');
        Route::get('/statistics', [App\Http\Controllers\Api\CashTransferController::class, 'statistics'])
            ->middleware('role:admin,manager');
        Route::get('/{id}', [App\Http\Controllers\Api\CashTransferController::class, 'show'])
            ->middleware('role:admin,manager');
        
        // Create transfer
        Route::post('/', [App\Http\Controllers\Api\CashTransferController::class, 'store'])
            ->middleware('role:admin,manager');
        
        // Approve transfer
        Route::post('/{id}/approve', [App\Http\Controllers\Api\CashTransferController::class, 'approve'])
            ->middleware('role:admin,manager');
        
        // Cancel transfer
        Route::post('/{id}/cancel', [App\Http\Controllers\Api\CashTransferController::class, 'cancel'])
            ->middleware('role:admin,manager');
    });
});

/*
|--------------------------------------------------------------------------
| Salary Deduction Routes (NEW!)
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'activity.log'])->group(function () {
    
    Route::prefix('salary-deductions')->group(function () {
        // Read operations
        Route::get('/', [App\Http\Controllers\Api\SalaryDeductionController::class, 'index'])
            ->middleware('role:admin,manager');
        Route::get('/statistics', [App\Http\Controllers\Api\SalaryDeductionController::class, 'statistics'])
            ->middleware('role:admin,manager');
        Route::get('/period/{year}/{month}', [App\Http\Controllers\Api\SalaryDeductionController::class, 'byPeriod'])
            ->middleware('role:admin,manager');
        Route::get('/{id}', [App\Http\Controllers\Api\SalaryDeductionController::class, 'show'])
            ->middleware('role:admin,manager');
        
        // Process single deduction
        Route::post('/', [App\Http\Controllers\Api\SalaryDeductionController::class, 'store'])
            ->middleware('role:admin,manager');
        
        // Batch process
        Route::post('/batch', [App\Http\Controllers\Api\SalaryDeductionController::class, 'batchProcess'])
            ->middleware('role:admin,manager');
    });
    
    // Member annual summary
    Route::get('/members/{userId}/salary-deductions/annual/{year}', 
        [App\Http\Controllers\Api\SalaryDeductionController::class, 'memberAnnualSummary'])
        ->middleware('role:admin,manager');
});

/*
|--------------------------------------------------------------------------
| Members Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'activity.log'])->group(function () {
    
    Route::prefix('members')->group(function () {
        // Profile
        Route::get('/profile', [App\Http\Controllers\Api\MemberController::class, 'profile']);
        
        // Statistics
        Route::get('/statistics', [App\Http\Controllers\Api\MemberController::class, 'statistics'])
            ->middleware('role:admin,manager');
        
        // Create member
        Route::post('/', [App\Http\Controllers\Api\MemberController::class, 'store'])
            ->middleware('role:admin,manager');
        
        // List members
        Route::get('/', [App\Http\Controllers\Api\MemberController::class, 'index'])
            ->middleware('role:admin,manager');
        
        // Member details
        Route::get('/{id}', [App\Http\Controllers\Api\MemberController::class, 'show']);
        
        // Update profile
        Route::put('/{id}', [App\Http\Controllers\Api\MemberController::class, 'update']);
        
        // Change password
        Route::post('/{id}/change-password', [App\Http\Controllers\Api\MemberController::class, 'changePassword']);
        
        // Financial summary
        Route::get('/{id}/financial-summary', [App\Http\Controllers\Api\MemberController::class, 'financialSummary']);
        
        // Activity history
        Route::get('/{id}/activity-history', [App\Http\Controllers\Api\MemberController::class, 'activityHistory']);
        
        // Update status
        Route::post('/{id}/update-status', [App\Http\Controllers\Api\MemberController::class, 'updateStatus'])
            ->middleware('role:admin');
    });
});

/*
|--------------------------------------------------------------------------
| Service Allowances Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'activity.log'])->group(function () {
    
    Route::prefix('service-allowances')->group(function () {
        
        // Read operations
        Route::get('/', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'index']);
        Route::get('/period-summary', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'periodSummary']);
        Route::get('/member/{userId}/history', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'memberHistory']);
        Route::get('/{id}', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'show']);
        
        // Write operations
        Route::post('/', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'store'])
            ->middleware('role:admin,manager');
        
        Route::post('/preview', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'preview'])
            ->middleware('role:admin,manager');
        
        Route::post('/{id}/mark-as-paid', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'markAsPaid'])
            ->middleware('role:admin,manager');

        // Import / Export
        Route::get('/export/template', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'downloadTemplate'])
            ->middleware('role:admin,manager');
        
        Route::post('/import/excel', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'importExcel'])
            ->middleware('role:admin,manager');
        
        Route::get('/export/excel', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'exportExcel'])
            ->middleware('role:admin,manager');
    });
});

/*
|--------------------------------------------------------------------------
| Gifts Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    Route::prefix('gifts')->group(function () {
        // Read operations
        Route::get('/', [App\Http\Controllers\Api\GiftController::class, 'index']);
        Route::get('/statistics', [App\Http\Controllers\Api\GiftController::class, 'statistics']);
        Route::get('/type/{type}', [App\Http\Controllers\Api\GiftController::class, 'getByType']);
        Route::get('/member/{userId}/history', [App\Http\Controllers\Api\GiftController::class, 'memberHistory']);
        Route::get('/{id}', [App\Http\Controllers\Api\GiftController::class, 'show']);
        
        // Write operations (Admin & Manager only)
        Route::post('/', [App\Http\Controllers\Api\GiftController::class, 'store'])
            ->middleware('role:admin,manager');
        Route::put('/{id}', [App\Http\Controllers\Api\GiftController::class, 'update'])
            ->middleware('role:admin,manager');
        Route::delete('/{id}', [App\Http\Controllers\Api\GiftController::class, 'destroy'])
            ->middleware('role:admin,manager');
        Route::post('/{id}/mark-as-distributed', [App\Http\Controllers\Api\GiftController::class, 'markAsDistributed'])
            ->middleware('role:admin,manager');
    });
});

/*
|--------------------------------------------------------------------------
| Dashboard Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    Route::prefix('dashboard')->group(function () {
        // Admin Dashboard
        Route::get('/admin', [App\Http\Controllers\Api\DashboardController::class, 'adminDashboard'])
            ->middleware('role:admin,manager');

        // Manager Dashboard
        Route::get('/manager', [App\Http\Controllers\Api\DashboardController::class, 'managerDashboard'])
            ->middleware('role:manager');
        
        // Member Dashboard
        Route::get('/member', [App\Http\Controllers\Api\DashboardController::class, 'memberDashboard'])
            ->middleware('role:anggota');
        
        // Quick Stats
        Route::get('/quick-stats', [App\Http\Controllers\Api\DashboardController::class, 'quickStats']);
    });
});

/*
|--------------------------------------------------------------------------
| Activity Logs Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    Route::prefix('activity-logs')->middleware('role:admin,manager')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\ActivityLogController::class, 'index']);
        Route::get('/statistics', [App\Http\Controllers\Api\ActivityLogController::class, 'statistics']);
        Route::get('/user/{userId}/history', [App\Http\Controllers\Api\ActivityLogController::class, 'userHistory']);
        Route::get('/{id}', [App\Http\Controllers\Api\ActivityLogController::class, 'show']);
    });
});

/*
|--------------------------------------------------------------------------
| Journals Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    Route::prefix('journals')->middleware('role:admin,manager')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\JournalController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\JournalController::class, 'store']);
        Route::get('/general-ledger', [App\Http\Controllers\Api\JournalController::class, 'generalLedger']);
        Route::get('/trial-balance', [App\Http\Controllers\Api\JournalController::class, 'trialBalance']);
        Route::get('/{id}', [App\Http\Controllers\Api\JournalController::class, 'show']);
        Route::put('/{id}', [App\Http\Controllers\Api\JournalController::class, 'update']);
        Route::delete('/{id}', [App\Http\Controllers\Api\JournalController::class, 'destroy']);
        Route::post('/{id}/lock', [App\Http\Controllers\Api\JournalController::class, 'lock']);
    });
});

/*
|--------------------------------------------------------------------------
| Assets Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    Route::prefix('assets')->middleware('role:admin,manager')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\AssetController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\AssetController::class, 'store']);
        Route::get('/summary', [App\Http\Controllers\Api\AssetController::class, 'summary']);
        Route::post('/calculate-all-depreciation', [App\Http\Controllers\Api\AssetController::class, 'calculateAllDepreciation']);
        Route::get('/{id}', [App\Http\Controllers\Api\AssetController::class, 'show']);
        Route::put('/{id}', [App\Http\Controllers\Api\AssetController::class, 'update']);
        Route::delete('/{id}', [App\Http\Controllers\Api\AssetController::class, 'destroy']);
        Route::post('/{id}/calculate-depreciation', [App\Http\Controllers\Api\AssetController::class, 'calculateDepreciation']);
        Route::get('/{id}/depreciation-schedule', [App\Http\Controllers\Api\AssetController::class, 'depreciationSchedule']);
    });
});