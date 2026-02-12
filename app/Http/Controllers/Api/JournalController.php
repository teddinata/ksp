<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\JournalRequest;
use App\Models\Journal;
use App\Models\ChartOfAccount;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of journals.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Journal::with([
                'details.chartOfAccount:id,code,name',
                'accountingPeriod:id,period_name',
                'creator:id,full_name'
            ]);

            // Filter by journal type
            if ($request->has('journal_type')) {
                $query->byType($request->journal_type);
            }

            // Filter by period
            if ($request->has('period_id')) {
                $query->byPeriod($request->period_id);
            }

            // Filter by locked status
            if ($request->has('is_locked')) {
                if ($request->boolean('is_locked')) {
                    $query->locked();
                }
                else {
                    $query->unlocked();
                }
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->dateRange($request->start_date, $request->end_date);
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('journal_number', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Sort
            $sortBy = $request->get('sort_by', 'transaction_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);

            if ($request->has('all') && $request->boolean('all')) {
                $journals = $query->get();

                $journals->each(function ($journal) {
                    $journal->type_name = $journal->type_name;
                });

                return $this->successResponse($journals, 'Journals retrieved successfully');
            }
            else {
                $journals = $query->paginate($perPage);

                $journals->getCollection()->each(function ($journal) {
                    $journal->type_name = $journal->type_name;
                });

                return $this->paginatedResponse($journals, 'Journals retrieved successfully');
            }

        }
        catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve journals: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Store a newly created journal.
     *
     * @param JournalRequest $request
     * @return JsonResponse
     */
    public function store(JournalRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $journal = Journal::createWithDetails($request->validated());

            DB::commit();

            $journal->load([
                'details.chartOfAccount:id,code,name',
                'accountingPeriod:id,period_name',
                'creator:id,full_name'
            ]);

            // Log activity
            \App\Models\ActivityLog::createLog([
                'activity' => 'create',
                'module' => 'journals',
                'description' => auth()->user()->full_name . ' membuat jurnal ' . $journal->journal_number,
            ]);

            return $this->successResponse(
                $journal,
                'Journal created successfully',
                201
            );

        }
        catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to create journal: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified journal.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $journal = Journal::with([
                'details.chartOfAccount:id,code,name,account_type',
                'accountingPeriod:id,period_name,start_date,end_date',
                'creator:id,full_name,email'
            ])->findOrFail($id);

            $journal->type_name = $journal->type_name;

            return $this->successResponse(
                $journal,
                'Journal retrieved successfully'
            );

        }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Journal not found', 404);
        }
        catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve journal: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update the specified journal.
     *
     * @param JournalRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(JournalRequest $request, int $id): JsonResponse
    {
        try {
            $journal = Journal::findOrFail($id);

            // Cannot update locked journal
            if ($journal->is_locked) {
                return $this->errorResponse(
                    'Cannot update locked journal',
                    400
                );
            }

            DB::beginTransaction();

            // Delete old details
            $journal->details()->delete();

            // Update journal
            $journal->update([
                'journal_type' => $request->journal_type,
                'description' => $request->description,
                'transaction_date' => $request->transaction_date,
                'accounting_period_id' => $request->accounting_period_id,
            ]);

            // Create new details
            foreach ($request->details as $detail) {
                \App\Models\JournalDetail::create([
                    'journal_id' => $journal->id,
                    'chart_of_account_id' => $detail['chart_of_account_id'],
                    'debit' => $detail['debit'] ?? 0,
                    'credit' => $detail['credit'] ?? 0,
                    'description' => $detail['description'] ?? null,
                ]);
            }

            // Recalculate totals
            $journal->calculateTotals();

            DB::commit();

            $journal->load([
                'details.chartOfAccount:id,code,name',
                'accountingPeriod:id,period_name',
                'creator:id,full_name'
            ]);

            // Log activity
            \App\Models\ActivityLog::createLog([
                'activity' => 'update',
                'module' => 'journals',
                'description' => auth()->user()->full_name . ' mengubah jurnal ' . $journal->journal_number,
            ]);

            return $this->successResponse(
                $journal,
                'Journal updated successfully'
            );

        }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return $this->errorResponse('Journal not found', 404);
        }
        catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to update journal: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove the specified journal.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $journal = Journal::findOrFail($id);

            // Cannot delete locked journal
            if ($journal->is_locked) {
                return $this->errorResponse(
                    'Cannot delete locked journal',
                    400
                );
            }

            // Cannot delete auto-generated journal
            if ($journal->journal_type === 'special') {
                return $this->errorResponse(
                    'Cannot delete auto-generated journal',
                    400
                );
            }

            DB::beginTransaction();

            $journalNumber = $journal->journal_number;
            $journal->details()->delete();
            $journal->delete();

            DB::commit();

            // Log activity
            \App\Models\ActivityLog::createLog([
                'activity' => 'delete',
                'module' => 'journals',
                'description' => auth()->user()->full_name . ' menghapus jurnal ' . $journalNumber,
            ]);

            return $this->successResponse(
                null,
                'Journal deleted successfully'
            );

        }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return $this->errorResponse('Journal not found', 404);
        }
        catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to delete journal: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Lock journal.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function lock(int $id): JsonResponse
    {
        try {
            $journal = Journal::findOrFail($id);

            if ($journal->is_locked) {
                return $this->errorResponse('Journal is already locked', 400);
            }

            $journal->lock();

            // Log activity
            \App\Models\ActivityLog::createLog([
                'activity' => 'update',
                'module' => 'journals',
                'description' => auth()->user()->full_name . ' mengunci jurnal ' . $journal->journal_number,
            ]);

            return $this->successResponse(
                $journal,
                'Journal locked successfully'
            );

        }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Journal not found', 404);
        }
        catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to lock journal: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get general ledger.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generalLedger(Request $request): JsonResponse
    {
        try {
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');
            $accountId = $request->get('account_id');

            $query = \App\Models\JournalDetail::with([
                'journal:id,journal_number,transaction_date,description',
                'chartOfAccount:id,code,name'
            ])
                ->whereHas('journal', function ($q) use ($startDate, $endDate) {
                if ($startDate && $endDate) {
                    $q->whereBetween('transaction_date', [$startDate, $endDate]);
                }
            });

            if ($accountId) {
                $query->byAccount($accountId);
            }

            $details = $query->orderBy('journal_id')->get();

            // Group by account
            $ledger = $details->groupBy('chart_of_account_id')->map(function ($accountDetails, $accountId) {
                $account = $accountDetails->first()->chartOfAccount;
                $balance = 0;

                $transactions = $accountDetails->map(function ($detail) use (&$balance) {
                        $balance += ($detail->debit - $detail->credit);

                        return [
                        'date' => $detail->journal->transaction_date->format('Y-m-d'),
                        'journal_number' => $detail->journal->journal_number,
                        'description' => $detail->description ?? $detail->journal->description,
                        'debit' => $detail->debit,
                        'credit' => $detail->credit,
                        'balance' => $balance,
                        ];
                    }
                    );

                    return [
                    'account' => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    ],
                    'transactions' => $transactions,
                    'total_debit' => $accountDetails->sum('debit'),
                    'total_credit' => $accountDetails->sum('credit'),
                    'balance' => $balance,
                    ];
                })->values();

            return $this->successResponse(
                $ledger,
                'General ledger retrieved successfully'
            );

        }
        catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve general ledger: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get trial balance.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function trialBalance(Request $request): JsonResponse
    {
        try {
            $periodId = $request->get('period_id');
            $endDate = $request->get('end_date', now()->format('Y-m-d'));

            $query = \App\Models\JournalDetail::with('chartOfAccount:id,code,name,account_type')
                ->whereHas('journal', function ($q) use ($endDate, $periodId) {
                $q->where('transaction_date', '<=', $endDate);
                if ($periodId) {
                    $q->where('accounting_period_id', $periodId);
                }
            });

            $details = $query->get();

            // Group by account and calculate balance
            $trialBalance = $details->groupBy('chart_of_account_id')->map(function ($accountDetails) {
                $account = $accountDetails->first()->chartOfAccount;
                $totalDebit = $accountDetails->sum('debit');
                $totalCredit = $accountDetails->sum('credit');
                $balance = $totalDebit - $totalCredit;

                return [
                'account_code' => $account->code,
                'account_name' => $account->name,
                'account_type' => $account->account_type,
                'debit' => $balance > 0 ? $balance : 0,
                'credit' => $balance < 0 ? abs($balance) : 0,
                ];
            })->values();

            // Calculate totals
            $totalDebit = $trialBalance->sum('debit');
            $totalCredit = $trialBalance->sum('credit');

            return $this->successResponse([
                'trial_balance' => $trialBalance,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'is_balanced' => round($totalDebit, 2) == round($totalCredit, 2),
                'end_date' => $endDate,
            ], 'Trial balance retrieved successfully');

        }
        catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve trial balance: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get Income Statement (Laporan Laba Rugi).
     * 
     * Menghitung pendapatan dan beban dari journal_details
     * berdasarkan kategori chart_of_accounts.
     * 
     * Supports:
     * - Filter by date range (start_date, end_date)
     * - Comparison with previous period (compare=true)
     * 
     * GET /api/journals/income-statement
     * 
     * @param Request $request
     * @return JsonResponse
     */
    /**
     * Get Income Statement / Laporan Laba Rugi / SHU.
     * 
     * Menghitung pendapatan dan beban dari journal_details
     * berdasarkan kategori chart_of_accounts (revenue & expenses).
     * 
     * Endpoint: GET /api/journals/income-statement
     * 
     * Query Parameters:
     *   - start_date  (default: awal bulan ini)
     *   - end_date    (default: akhir bulan ini)
     *   - compare     (boolean, default: false — tampilkan perbandingan periode sebelumnya)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        try {
            $endDate = $request->get('end_date', now()->endOfMonth()->format('Y-m-d'));
            $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));

            // ==================== CURRENT PERIOD ====================
            $currentData = $this->calculateIncomeData($startDate, $endDate);

            $response = [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'label' => \Carbon\Carbon::parse($startDate)->translatedFormat('d F Y')
                    . ' s/d '
                    . \Carbon\Carbon::parse($endDate)->translatedFormat('d F Y'),
                ],
                'revenue' => $currentData['revenue'],
                'expenses' => $currentData['expenses'],
                'summary' => $currentData['summary'],
            ];

            // ==================== COMPARISON (optional) ====================
            if ($request->boolean('compare', false)) {
                $duration = \Carbon\Carbon::parse($startDate)->diffInDays(\Carbon\Carbon::parse($endDate));
                $prevEnd = \Carbon\Carbon::parse($startDate)->subDay()->format('Y-m-d');
                $prevStart = \Carbon\Carbon::parse($prevEnd)->subDays($duration)->format('Y-m-d');

                $prevData = $this->calculateIncomeData($prevStart, $prevEnd);

                $response['comparison'] = [
                    'period' => [
                        'start_date' => $prevStart,
                        'end_date' => $prevEnd,
                        'label' => \Carbon\Carbon::parse($prevStart)->translatedFormat('d F Y')
                        . ' s/d '
                        . \Carbon\Carbon::parse($prevEnd)->translatedFormat('d F Y'),
                    ],
                    'revenue' => $prevData['revenue'],
                    'expenses' => $prevData['expenses'],
                    'summary' => $prevData['summary'],
                ];

                // Variance
                $curNet = $currentData['summary']['net_income'];
                $prevNet = $prevData['summary']['net_income'];
                $change = $curNet - $prevNet;

                $response['variance'] = [
                    'net_income_change' => $change,
                    'net_income_percent' => $prevNet != 0
                    ? round(($change / abs($prevNet)) * 100, 2)
                    : ($curNet != 0 ? 100 : 0),
                    'revenue_change' => $currentData['summary']['total_revenue'] - $prevData['summary']['total_revenue'],
                    'expenses_change' => $currentData['summary']['total_expenses'] - $prevData['summary']['total_expenses'],
                    'trend' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
                ];
            }

            return $this->successResponse($response, 'Laporan Laba Rugi berhasil diambil');

        }
        catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal mengambil Laporan Laba Rugi: ' . $e->getMessage(),
                500
            );
        }
    }


    // ==================== 2. BALANCE SHEET (NERACA) ====================

    /**
     * Get Balance Sheet / Laporan Neraca.
     * 
     * Menghitung posisi keuangan (assets, liabilities, equity)
     * sampai dengan tanggal tertentu.
     * 
     * Endpoint: GET /api/journals/balance-sheet
     * 
     * Query Parameters:
     *   - as_of_date (default: hari ini)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        try {
            $asOfDate = $request->get('as_of_date', now()->format('Y-m-d'));

            // Ambil semua journal_details sampai tanggal tertentu
            $details = \App\Models\JournalDetail::with('chartOfAccount:id,code,name,category,account_type,is_debit')
                ->whereHas('journal', function ($q) use ($asOfDate) {
                $q->where('transaction_date', '<=', $asOfDate);
            })
                ->get();

            // Group by category
            $grouped = $details->groupBy(fn($d) => $d->chartOfAccount->category);

            $assets = $this->buildCategoryReport($grouped->get('assets', collect()));
            $liabilities = $this->buildCategoryReport($grouped->get('liabilities', collect()));
            $equity = $this->buildCategoryReport($grouped->get('equity', collect()));

            // Hitung laba berjalan (revenue - expenses sampai tanggal)
            $revDetails = $grouped->get('revenue', collect());
            $expDetails = $grouped->get('expenses', collect());
            $totalRev = $revDetails->sum('credit') - $revDetails->sum('debit');
            $totalExp = $expDetails->sum('debit') - $expDetails->sum('credit');
            $netIncome = $totalRev - $totalExp;

            $totalEquityWithIncome = $equity['total'] + $netIncome;

            return $this->successResponse([
                'as_of_date' => $asOfDate,
                'label' => 'Neraca per ' . \Carbon\Carbon::parse($asOfDate)->translatedFormat('d F Y'),
                'assets' => [
                    'accounts' => $assets['accounts'],
                    'total' => $assets['total'],
                ],
                'liabilities' => [
                    'accounts' => $liabilities['accounts'],
                    'total' => $liabilities['total'],
                ],
                'equity' => [
                    'accounts' => $equity['accounts'],
                    'total_before_income' => $equity['total'],
                    'net_income_current_year' => $netIncome,
                    'total' => $totalEquityWithIncome,
                ],
                'summary' => [
                    'total_assets' => $assets['total'],
                    'total_liabilities_and_equity' => $liabilities['total'] + $totalEquityWithIncome,
                    'is_balanced' => abs($assets['total'] - ($liabilities['total'] + $totalEquityWithIncome)) < 0.01,
                    'selisih' => round($assets['total'] - ($liabilities['total'] + $totalEquityWithIncome), 2),
                ],
            ], 'Neraca berhasil diambil');

        }
        catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal mengambil Neraca: ' . $e->getMessage(),
                500
            );
        }
    }


    // ==================== 3. CASH FLOW (ARUS KAS) ====================

    /**
     * Get Cash Flow Summary / Ringkasan Arus Kas.
     * 
     * Menghitung pergerakan kas (masuk/keluar) per akun kas.
     * 
     * Endpoint: GET /api/journals/cash-flow
     * 
     * Query Parameters:
     *   - start_date  (default: awal bulan ini)
     *   - end_date    (default: akhir bulan ini)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function cashFlow(Request $request): JsonResponse
    {
        try {
            $endDate = $request->get('end_date', now()->endOfMonth()->format('Y-m-d'));
            $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));

            // Ambil semua detail journal yang terkait akun kas (account_type = Cash atau Bank)
            $cashDetails = \App\Models\JournalDetail::with([
                'chartOfAccount:id,code,name,category,account_type',
                'journal:id,journal_number,transaction_date,description,source_module',
            ])
                ->whereHas('chartOfAccount', function ($q) {
                $q->whereIn('account_type', ['Cash', 'Bank', 'cash', 'bank']);
            })
                ->whereHas('journal', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('transaction_date', [$startDate, $endDate]);
            })
                ->get();

            // Group by cash account
            $cashAccounts = $cashDetails->groupBy('chart_of_account_id')->map(function ($items) {
                $account = $items->first()->chartOfAccount;
                $totalIn = $items->sum('debit');
                $totalOut = $items->sum('credit');
                $netFlow = $totalIn - $totalOut;

                // Breakdown by source_module
                $byModule = $items->groupBy(fn($d) => $d->journal->source_module ?? 'manual')->map(function ($moduleItems, $module) {
                        return [
                        'module' => $module,
                        'kas_masuk' => $moduleItems->sum('debit'),
                        'kas_keluar' => $moduleItems->sum('credit'),
                        'net' => $moduleItems->sum('debit') - $moduleItems->sum('credit'),
                        ];
                    }
                    )->values();

                    return [
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'kas_masuk' => $totalIn,
                    'kas_keluar' => $totalOut,
                    'net_flow' => $netFlow,
                    'breakdown' => $byModule,
                    ];
                })->values()->sortBy('account_code')->values();

            $grandTotalIn = $cashAccounts->sum('kas_masuk');
            $grandTotalOut = $cashAccounts->sum('kas_keluar');

            return $this->successResponse([
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'label' => \Carbon\Carbon::parse($startDate)->translatedFormat('d F Y')
                    . ' s/d '
                    . \Carbon\Carbon::parse($endDate)->translatedFormat('d F Y'),
                ],
                'cash_accounts' => $cashAccounts,
                'summary' => [
                    'total_kas_masuk' => $grandTotalIn,
                    'total_kas_keluar' => $grandTotalOut,
                    'net_cash_flow' => $grandTotalIn - $grandTotalOut,
                ],
            ], 'Arus Kas berhasil diambil');

        }
        catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal mengambil Arus Kas: ' . $e->getMessage(),
                500
            );
        }
    }


    // ==================== PRIVATE HELPERS ====================

    /**
     * Calculate income statement data for a date range.
     * 
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    private function calculateIncomeData(string $startDate, string $endDate): array
    {
        $details = \App\Models\JournalDetail::with('chartOfAccount:id,code,name,category,account_type,is_debit')
            ->whereHas('journal', function ($q) use ($startDate, $endDate) {
            $q->whereBetween('transaction_date', [$startDate, $endDate]);
        })
            ->get();

        // Pisah revenue dan expenses
        $revItems = $details->filter(fn($d) => $d->chartOfAccount->category === 'revenue');
        $expItems = $details->filter(fn($d) => $d->chartOfAccount->category === 'expenses');

        // Group revenue by account → hitung saldo (credit - debit, karena revenue normal credit)
        $revenueAccounts = $revItems->groupBy('chart_of_account_id')->map(function ($items) {
            $coa = $items->first()->chartOfAccount;
            $balance = $items->sum('credit') - $items->sum('debit');
            return [
            'account_code' => $coa->code,
            'account_name' => $coa->name,
            'amount' => round($balance, 2),
            ];
        })->values()->sortBy('account_code')->values();

        // Group expenses by account → hitung saldo (debit - credit, karena expense normal debit)
        $expenseAccounts = $expItems->groupBy('chart_of_account_id')->map(function ($items) {
            $coa = $items->first()->chartOfAccount;
            $balance = $items->sum('debit') - $items->sum('credit');
            return [
            'account_code' => $coa->code,
            'account_name' => $coa->name,
            'amount' => round($balance, 2),
            ];
        })->values()->sortBy('account_code')->values();

        $totalRevenue = $revenueAccounts->sum('amount');
        $totalExpenses = $expenseAccounts->sum('amount');
        $netIncome = $totalRevenue - $totalExpenses;

        return [
            'revenue' => [
                'accounts' => $revenueAccounts,
                'total' => round($totalRevenue, 2),
            ],
            'expenses' => [
                'accounts' => $expenseAccounts,
                'total' => round($totalExpenses, 2),
            ],
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_expenses' => round($totalExpenses, 2),
                'net_income' => round($netIncome, 2),
                'operating_margin' => $totalRevenue > 0
                ? round(($netIncome / $totalRevenue) * 100, 2)
                : 0,
                'is_profit' => $netIncome >= 0,
            ],
        ];
    }

    /**
     * Build account balance report for a category.
     * 
     * @param \Illuminate\Support\Collection $details
     * @return array
     */
    private function buildCategoryReport($details): array
    {
        $accounts = $details->groupBy('chart_of_account_id')->map(function ($items) {
            $coa = $items->first()->chartOfAccount;
            $totalDebit = $items->sum('debit');
            $totalCredit = $items->sum('credit');

            // Hitung saldo berdasarkan saldo normal akun
            $balance = $coa->is_debit
                ? ($totalDebit - $totalCredit)
                : ($totalCredit - $totalDebit);

            return [
            'account_code' => $coa->code,
            'account_name' => $coa->name,
            'account_type' => $coa->account_type,
            'balance' => round($balance, 2),
            ];
        })->values()->sortBy('account_code')->values();

        return [
            'accounts' => $accounts,
            'total' => round($accounts->sum('balance'), 2),
        ];
    }


    // ==================== PRIVATE HELPERS ====================

    /**
     * Calculate income statement data for a period.
     */
    private static function calculateIncomeStatementData(string $startDate, string $endDate): array
    {
        $details = \App\Models\JournalDetail::with('chartOfAccount:id,code,name,category,account_type,is_debit')
            ->whereHas('journal', function ($q) use ($startDate, $endDate) {
            $q->whereBetween('transaction_date', [$startDate, $endDate]);
        })
            ->get();

        // Separate revenue and expenses
        $revenueDetails = $details->filter(fn($d) => $d->chartOfAccount->category === 'revenue');
        $expenseDetails = $details->filter(fn($d) => $d->chartOfAccount->category === 'expenses');

        // Group revenue by account
        $revenueAccounts = $revenueDetails->groupBy('chart_of_account_id')->map(function ($items) {
            $account = $items->first()->chartOfAccount;
            $totalCredit = $items->sum('credit');
            $totalDebit = $items->sum('debit');
            // Revenue normal balance = credit
            $balance = $totalCredit - $totalDebit;

            return [
            'account_code' => $account->code,
            'account_name' => $account->name,
            'amount' => $balance,
            ];
        })->values()->sortBy('account_code')->values();

        // Group expenses by account
        $expenseAccounts = $expenseDetails->groupBy('chart_of_account_id')->map(function ($items) {
            $account = $items->first()->chartOfAccount;
            $totalDebit = $items->sum('debit');
            $totalCredit = $items->sum('credit');
            // Expense normal balance = debit
            $balance = $totalDebit - $totalCredit;

            return [
            'account_code' => $account->code,
            'account_name' => $account->name,
            'amount' => $balance,
            ];
        })->values()->sortBy('account_code')->values();

        $totalRevenue = $revenueAccounts->sum('amount');
        $totalExpenses = $expenseAccounts->sum('amount');
        $netIncome = $totalRevenue - $totalExpenses;

        // Margins
        $operatingMargin = $totalRevenue > 0
            ? round(($netIncome / $totalRevenue) * 100, 2)
            : 0;

        return [
            'revenue' => [
                'accounts' => $revenueAccounts,
                'total' => $totalRevenue,
            ],
            'expenses' => [
                'accounts' => $expenseAccounts,
                'total' => $totalExpenses,
            ],
            'summary' => [
                'total_revenue' => $totalRevenue,
                'total_expenses' => $totalExpenses,
                'net_income' => $netIncome,
                'operating_margin' => $operatingMargin,
                'is_profit' => $netIncome >= 0,
            ],
        ];
    }

    /**
     * Calculate balance for a category (assets, liabilities, equity).
     */
    private static function calculateCategoryBalance($details, bool $isDebitNormal): array
    {
        $accounts = $details->groupBy('chart_of_account_id')->map(function ($items) use ($isDebitNormal) {
            $account = $items->first()->chartOfAccount;
            $totalDebit = $items->sum('debit');
            $totalCredit = $items->sum('credit');

            // Contra accounts have reversed normal balance
            $effectiveDebitNormal = $account->is_debit;
            $balance = $effectiveDebitNormal
                ? ($totalDebit - $totalCredit)
                : ($totalCredit - $totalDebit);

            return [
            'account_code' => $account->code,
            'account_name' => $account->name,
            'account_type' => $account->account_type,
            'balance' => $balance,
            ];
        })->values()->sortBy('account_code')->values();

        $total = $accounts->sum('balance');

        return [
            'accounts' => $accounts,
            'total' => $total,
        ];
    }
}