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
                } else {
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
                $query->where(function($q) use ($search) {
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
                
                $journals->each(function($journal) {
                    $journal->type_name = $journal->type_name;
                });

                return $this->successResponse($journals, 'Journals retrieved successfully');
            } else {
                $journals = $query->paginate($perPage);
                
                $journals->getCollection()->each(function($journal) {
                    $journal->type_name = $journal->type_name;
                });

                return $this->paginatedResponse($journals, 'Journals retrieved successfully');
            }

        } catch (\Exception $e) {
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

        } catch (\Exception $e) {
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

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Journal not found', 404);
        } catch (\Exception $e) {
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

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return $this->errorResponse('Journal not found', 404);
        } catch (\Exception $e) {
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

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return $this->errorResponse('Journal not found', 404);
        } catch (\Exception $e) {
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

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Journal not found', 404);
        } catch (\Exception $e) {
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
            ->whereHas('journal', function($q) use ($startDate, $endDate) {
                if ($startDate && $endDate) {
                    $q->whereBetween('transaction_date', [$startDate, $endDate]);
                }
            });

            if ($accountId) {
                $query->byAccount($accountId);
            }

            $details = $query->orderBy('journal_id')->get();

            // Group by account
            $ledger = $details->groupBy('chart_of_account_id')->map(function($accountDetails, $accountId) {
                $account = $accountDetails->first()->chartOfAccount;
                $balance = 0;

                $transactions = $accountDetails->map(function($detail) use (&$balance) {
                    $balance += ($detail->debit - $detail->credit);
                    
                    return [
                        'date' => $detail->journal->transaction_date->format('Y-m-d'),
                        'journal_number' => $detail->journal->journal_number,
                        'description' => $detail->description ?? $detail->journal->description,
                        'debit' => $detail->debit,
                        'credit' => $detail->credit,
                        'balance' => $balance,
                    ];
                });

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

        } catch (\Exception $e) {
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
                ->whereHas('journal', function($q) use ($endDate, $periodId) {
                    $q->where('transaction_date', '<=', $endDate);
                    if ($periodId) {
                        $q->where('accounting_period_id', $periodId);
                    }
                });

            $details = $query->get();

            // Group by account and calculate balance
            $trialBalance = $details->groupBy('chart_of_account_id')->map(function($accountDetails) {
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

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve trial balance: ' . $e->getMessage(),
                500
            );
        }
    }
}