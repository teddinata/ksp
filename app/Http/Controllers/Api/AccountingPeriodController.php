<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountingPeriodRequest;
use App\Models\AccountingPeriod;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AccountingPeriodController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of accounting periods.
     * 
     * Business Logic:
     * - Shows all periods (open and closed)
     * - Can filter by status (open/closed)
     * - Can filter by year
     * - Sorted by start_date (newest first)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = AccountingPeriod::query()->with('closedBy:id,full_name,email');

            // Filter by status
            if ($request->has('is_closed')) {
                if ($request->boolean('is_closed')) {
                    $query->closed();
                } else {
                    $query->open();
                }
            }

            // Filter by year
            if ($request->has('year')) {
                $query->forYear($request->year);
            }

            // Search by period name
            if ($request->has('search')) {
                $search = $request->search;
                $query->where('period_name', 'like', "%{$search}%");
            }

            // Sort
            $sortBy = $request->get('sort_by', 'start_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                $periods = $query->get();
                
                // Add computed attributes
                $periods->map(function($period) {
                    $period->is_active = $period->isActive();
                    return $period;
                });
                
                return $this->successResponse($periods, 'Accounting periods retrieved successfully');
            } else {
                $periods = $query->paginate($perPage);
                
                // Add computed attributes
                $periods->getCollection()->transform(function($period) {
                    $period->is_active = $period->isActive();
                    return $period;
                });
                
                return $this->paginatedResponse($periods, 'Accounting periods retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve accounting periods: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Store a newly created period.
     * 
     * Business Logic:
     * - Only Admin can create
     * - Auto-generate period_name if not provided
     * - Check for date overlaps
     * - Max duration: 366 days (1 year)
     *
     * @param AccountingPeriodRequest $request
     * @return JsonResponse
     */
    public function store(AccountingPeriodRequest $request): JsonResponse
    {
        try {
            $period = AccountingPeriod::create([
                'period_name' => $request->period_name,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'is_closed' => false,
            ]);

            return $this->successResponse(
                $period,
                'Accounting period created successfully',
                201
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create accounting period: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified period.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $period = AccountingPeriod::with('closedBy:id,full_name,email')
                ->findOrFail($id);

            // Add computed attributes
            $period->is_active = $period->isActive();
            $period->has_journals = $period->hasJournals();
            $period->journal_count = $period->journals()->count();

            return $this->successResponse(
                $period,
                'Accounting period retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Accounting period not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve accounting period: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update the specified period.
     * 
     * Business Logic:
     * - Only Admin can update
     * - Cannot update closed periods
     * - Check for date overlaps (exclude current period)
     *
     * @param AccountingPeriodRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(AccountingPeriodRequest $request, int $id): JsonResponse
    {
        try {
            $period = AccountingPeriod::findOrFail($id);

            // Check if period is closed
            if ($period->is_closed) {
                return $this->errorResponse(
                    'Cannot update a closed accounting period',
                    400
                );
            }

            $period->update([
                'period_name' => $request->period_name,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
            ]);

            return $this->successResponse(
                $period,
                'Accounting period updated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Accounting period not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update accounting period: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove the specified period.
     * 
     * Business Logic:
     * - Only Admin can delete
     * - Cannot delete closed periods
     * - Cannot delete periods with journals (future implementation)
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $period = AccountingPeriod::findOrFail($id);

            // Check if period is closed
            if ($period->is_closed) {
                return $this->errorResponse(
                    'Cannot delete a closed accounting period',
                    400
                );
            }

            // Future: Check if period has journals
            // if ($period->hasJournals()) {
            //     return $this->errorResponse(
            //         'Cannot delete period with existing journals',
            //         400
            //     );
            // }

            $period->delete();

            return $this->successResponse(
                null,
                'Accounting period deleted successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Accounting period not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to delete accounting period: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get the current active period.
     * 
     * Returns the period that includes today's date and is not closed.
     *
     * @return JsonResponse
     */
    public function getActive(): JsonResponse
    {
        try {
            $period = AccountingPeriod::active()->first();

            if (!$period) {
                return $this->errorResponse(
                    'No active accounting period found',
                    404
                );
            }

            $period->is_active = true;
            $period->has_journals = $period->hasJournals();
            $period->journal_count = $period->journals()->count();

            return $this->successResponse(
                $period,
                'Active accounting period retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve active period: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Close an accounting period.
     * 
     * Business Logic:
     * - Only Admin can close
     * - Period must not be already closed
     * - Locks all journals in this period (future implementation)
     * - Records who closed it and when
     *
     * @param int $id
     * @return JsonResponse
     */
    public function close(int $id): JsonResponse
    {
        try {
            $period = AccountingPeriod::findOrFail($id);

            // Check if already closed
            if ($period->is_closed) {
                return $this->errorResponse(
                    'Accounting period is already closed',
                    400
                );
            }

            $user = auth()->user();
            $period->close($user->id);

            $period->load('closedBy:id,full_name,email');

            return $this->successResponse(
                $period,
                'Accounting period closed successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Accounting period not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to close accounting period: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Reopen a closed accounting period.
     * 
     * Business Logic:
     * - Only Admin can reopen
     * - Period must be closed
     * - Unlocks all journals in this period (future implementation)
     *
     * @param int $id
     * @return JsonResponse
     */
    public function reopen(int $id): JsonResponse
    {
        try {
            $period = AccountingPeriod::findOrFail($id);

            // Check if period is open
            if (!$period->is_closed) {
                return $this->errorResponse(
                    'Accounting period is already open',
                    400
                );
            }

            $period->reopen();

            return $this->successResponse(
                $period,
                'Accounting period reopened successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Accounting period not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to reopen accounting period: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get periods summary by year.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSummary(Request $request): JsonResponse
    {
        try {
            $year = $request->get('year', date('Y'));

            $periods = AccountingPeriod::forYear($year)
                ->orderBy('start_date')
                ->get();

            $summary = [
                'year' => $year,
                'total_periods' => $periods->count(),
                'open_periods' => $periods->where('is_closed', false)->count(),
                'closed_periods' => $periods->where('is_closed', true)->count(),
                'current_active' => AccountingPeriod::active()->first(),
                'periods' => $periods->map(function($period) {
                    return [
                        'id' => $period->id,
                        'period_name' => $period->period_name,
                        'start_date' => $period->start_date->format('Y-m-d'),
                        'end_date' => $period->end_date->format('Y-m-d'),
                        'is_closed' => $period->is_closed,
                        'is_active' => $period->isActive(),
                        'period_type' => $period->period_type,
                    ];
                }),
            ];

            return $this->successResponse(
                $summary,
                'Periods summary retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve summary: ' . $e->getMessage(),
                500
            );
        }
    }
}