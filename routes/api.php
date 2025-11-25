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
        // List & Summary (All authenticated users can view)
        Route::get('/', [App\Http\Controllers\Api\CashAccountController::class, 'index']);
        Route::get('/summary', [App\Http\Controllers\Api\CashAccountController::class, 'getSummary']);
        Route::get('/{id}', [App\Http\Controllers\Api\CashAccountController::class, 'show']);
        
        // Create, Update, Delete (Admin & Manager only)
        Route::post('/', [App\Http\Controllers\Api\CashAccountController::class, 'store'])
            ->middleware('role:admin,manager');
        Route::put('/{id}', [App\Http\Controllers\Api\CashAccountController::class, 'update'])
            ->middleware('role:admin');
        Route::delete('/{id}', [App\Http\Controllers\Api\CashAccountController::class, 'destroy'])
            ->middleware('role:admin');
        
        // Manager Assignment (Admin only)
        Route::prefix('{cashAccountId}/managers')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\CashAccountManagerController::class, 'index'])
                ->middleware('role:admin,manager');
            Route::post('/', [App\Http\Controllers\Api\CashAccountManagerController::class, 'store'])
                ->middleware('role:admin');
            Route::delete('/{managerId}', [App\Http\Controllers\Api\CashAccountManagerController::class, 'destroy'])
                ->middleware('role:admin');
        });
        
        // Interest Rates (Admin & assigned Manager)
        Route::prefix('{cashAccountId}/interest-rates')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\InterestRateController::class, 'index']);
            Route::post('/', [App\Http\Controllers\Api\InterestRateController::class, 'store'])
                ->middleware('role:admin,manager');
        });
    });
    
    // Interest Rates - Direct access
    Route::prefix('interest-rates')->group(function () {
        Route::get('/current', [App\Http\Controllers\Api\InterestRateController::class, 'getCurrentRates']);
        Route::put('/{id}', [App\Http\Controllers\Api\InterestRateController::class, 'update'])
            ->middleware('role:admin,manager');
        Route::delete('/{id}', [App\Http\Controllers\Api\InterestRateController::class, 'destroy'])
            ->middleware('role:admin');
    });
    
    // Manager's Dashboard - Get accounts managed by a user
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
    
    // Accounting Periods
    Route::prefix('accounting-periods')->group(function () {
        // Read operations (All authenticated users)
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
        
        // Period closing (Admin only)
        Route::post('/{id}/close', [App\Http\Controllers\Api\AccountingPeriodController::class, 'close'])
            ->middleware('role:admin');
        Route::post('/{id}/reopen', [App\Http\Controllers\Api\AccountingPeriodController::class, 'reopen'])
            ->middleware('role:admin');
    });
});

/*
|--------------------------------------------------------------------------
| Savings Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'activity.log'])->group(function () {
    
    // Savings
    Route::prefix('savings')->group(function () {
        // Read operations (All authenticated users with access control)
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
        
        // Approval (Admin & Manager only)
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
    
    // Loans
    Route::prefix('loans')->group(function () {
        // Read operations (All authenticated users with access control)
        Route::get('/', [App\Http\Controllers\Api\LoanController::class, 'index']);
        Route::get('/summary', [App\Http\Controllers\Api\LoanController::class, 'getSummary']);
        Route::post('/simulate', [App\Http\Controllers\Api\LoanController::class, 'simulate']);
        Route::get('/{id}', [App\Http\Controllers\Api\LoanController::class, 'show']);
        
        // Write operations (Admin & Manager only)
        Route::post('/', [App\Http\Controllers\Api\LoanController::class, 'store'])
            ->middleware('role:admin,manager,anggota');
        Route::put('/{id}', [App\Http\Controllers\Api\LoanController::class, 'update'])
            ->middleware('role:admin,manager');
        Route::delete('/{id}', [App\Http\Controllers\Api\LoanController::class, 'destroy'])
            ->middleware('role:admin,manager');
        
        // Approval (Admin & Manager only)
        Route::post('/{id}/approve', [App\Http\Controllers\Api\LoanController::class, 'approve'])
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
| Members Routes (UPDATED with CREATE)
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'activity.log'])->group(function () {
    
    // Members
    Route::prefix('members')->group(function () {
        // Profile (accessible by all authenticated users)
        Route::get('/profile', [App\Http\Controllers\Api\MemberController::class, 'profile']);
        
        // Statistics (Admin & Manager only)
        Route::get('/statistics', [App\Http\Controllers\Api\MemberController::class, 'statistics'])
            ->middleware('role:admin,manager');
        
        // Create member (Admin & Manager only) - NEW!
        Route::post('/', [App\Http\Controllers\Api\MemberController::class, 'store'])
            ->middleware('role:admin,manager');
        
        // List members (Admin & Manager only)
        Route::get('/', [App\Http\Controllers\Api\MemberController::class, 'index'])
            ->middleware('role:admin,manager');
        
        // Member details (accessible with access control)
        Route::get('/{id}', [App\Http\Controllers\Api\MemberController::class, 'show']);
        
        // Update profile (accessible with access control)
        Route::put('/{id}', [App\Http\Controllers\Api\MemberController::class, 'update']);
        
        // Change password (accessible with access control)
        Route::post('/{id}/change-password', [App\Http\Controllers\Api\MemberController::class, 'changePassword']);
        
        // Financial summary (accessible with access control)
        Route::get('/{id}/financial-summary', [App\Http\Controllers\Api\MemberController::class, 'financialSummary']);
        
        // Activity history (accessible with access control)
        Route::get('/{id}/activity-history', [App\Http\Controllers\Api\MemberController::class, 'activityHistory']);
        
        // Update status (Admin only)
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
    
    // Service Allowances
    Route::prefix('service-allowances')->group(function () {
        // Read operations (All authenticated users with access control)
        Route::get('/', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'index']);
        Route::get('/period-summary', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'periodSummary']);
        Route::get('/member/{userId}/history', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'memberHistory']);
        Route::get('/{id}', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'show']);
        
        // Distribution & Payment (Admin & Manager only)
        Route::post('/distribute', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'distribute'])
            ->middleware('role:admin,manager');
        Route::post('/calculate', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'calculate'])
            ->middleware('role:admin,manager');
        Route::post('/{id}/mark-as-paid', [App\Http\Controllers\Api\ServiceAllowanceController::class, 'markAsPaid'])
            ->middleware('role:admin,manager');
    });
});

/*
|--------------------------------------------------------------------------
| Gifts Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    // Gifts
    Route::prefix('gifts')->group(function () {
        // Read operations (All authenticated users with access control)
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
    
    // Dashboard
    Route::prefix('dashboard')->group(function () {
        // Admin Dashboard (Admin & Manager only)
        Route::get('/admin', [App\Http\Controllers\Api\DashboardController::class, 'adminDashboard'])
            ->middleware('role:admin,manager');
        
        // Member Dashboard (Members only)
        Route::get('/member', [App\Http\Controllers\Api\DashboardController::class, 'memberDashboard'])
            ->middleware('role:anggota');
        
        // Quick Stats (All users)
        Route::get('/quick-stats', [App\Http\Controllers\Api\DashboardController::class, 'quickStats']);
    });
});

/*
|--------------------------------------------------------------------------
| Activity Logs Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth'])->group(function () {
    
    // Activity Logs (Admin & Manager only)
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
    
    // Journals (Admin & Manager only)
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
    
    // Assets (Admin & Manager only)
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