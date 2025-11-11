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
Route::middleware(['jwt.auth'])->group(function () {
    
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
Route::middleware(['jwt.auth'])->group(function () {
    
    // Loans
    Route::prefix('loans')->group(function () {
        // Read operations (All authenticated users with access control)
        Route::get('/', [App\Http\Controllers\Api\LoanController::class, 'index']);
        Route::get('/summary', [App\Http\Controllers\Api\LoanController::class, 'getSummary']);
        Route::post('/simulate', [App\Http\Controllers\Api\LoanController::class, 'simulate']);
        Route::get('/{id}', [App\Http\Controllers\Api\LoanController::class, 'show']);
        
        // Write operations (Admin & Manager only)
        Route::post('/', [App\Http\Controllers\Api\LoanController::class, 'store'])
            ->middleware('role:admin,manager');
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