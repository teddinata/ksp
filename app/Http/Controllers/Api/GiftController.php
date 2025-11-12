<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GiftRequest;
use App\Models\Gift;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GiftController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of gifts.
     * 
     * Business Logic:
     * - Admin/Manager: Can see all gifts
     * - Member: Can only see their own gifts
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Gift::with([
                'user:id,full_name,employee_id',
                'distributedBy:id,full_name'
            ]);

            // Access Control: Member only sees own gifts
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            // Filter by user
            if ($request->has('user_id') && ($user->isAdmin() || $user->isManager())) {
                $query->byUser($request->user_id);
            }

            // Filter by gift type
            if ($request->has('gift_type')) {
                $query->byType($request->gift_type);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            // Filter by year
            if ($request->has('year')) {
                $query->byYear($request->year);
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->dateRange($request->start_date, $request->end_date);
            }

            // Search by gift name
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('gift_name', 'like', "%{$search}%")
                      ->orWhereHas('user', function($q2) use ($search) {
                          $q2->where('full_name', 'like', "%{$search}%")
                             ->orWhere('employee_id', 'like', "%{$search}%");
                      });
                });
            }

            // Sort
            $sortBy = $request->get('sort_by', 'distribution_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                $gifts = $query->get();
                
                // Add computed attributes
                $gifts->each(function($gift) {
                    $gift->type_name = $gift->type_name;
                    $gift->status_name = $gift->status_name;
                });

                return $this->successResponse($gifts, 'Gifts retrieved successfully');
            } else {
                $gifts = $query->paginate($perPage);
                
                // Add computed attributes
                $gifts->getCollection()->each(function($gift) {
                    $gift->type_name = $gift->type_name;
                    $gift->status_name = $gift->status_name;
                });

                return $this->paginatedResponse($gifts, 'Gifts retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve gifts: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified gift.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Gift::with([
                'user:id,full_name,employee_id,email',
                'distributedBy:id,full_name'
            ]);

            // Access Control: Member only sees own gifts
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            $gift = $query->findOrFail($id);

            // Add computed attributes
            $gift->type_name = $gift->type_name;
            $gift->status_name = $gift->status_name;

            return $this->successResponse(
                $gift,
                'Gift retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Gift not found or access denied', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve gift: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Store a newly created gift or distribute to members.
     *
     * @param GiftRequest $request
     * @return JsonResponse
     */
    public function store(GiftRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // If user_ids provided, distribute to specific users
            // Otherwise distribute to all active members
            $result = Gift::distributeToMembers(
                $request->gift_type,
                $request->gift_name,
                $request->gift_value,
                $request->distribution_date,
                $user->id,
                $request->user_ids
            );

            return $this->successResponse(
                $result,
                'Gifts distributed successfully',
                201
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to distribute gifts: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update the specified gift.
     *
     * @param GiftRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(GiftRequest $request, int $id): JsonResponse
    {
        try {
            $gift = Gift::findOrFail($id);

            // Cannot update distributed gifts
            if ($gift->isDistributed()) {
                return $this->errorResponse(
                    'Cannot update distributed gift',
                    400
                );
            }

            $gift->update([
                'gift_type' => $request->gift_type,
                'gift_name' => $request->gift_name,
                'gift_value' => $request->gift_value,
                'distribution_date' => $request->distribution_date,
                'notes' => $request->notes,
            ]);

            $gift->load(['user:id,full_name,employee_id', 'distributedBy:id,full_name']);

            return $this->successResponse(
                $gift,
                'Gift updated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Gift not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update gift: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove the specified gift.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $gift = Gift::findOrFail($id);

            // Cannot delete distributed gifts
            if ($gift->isDistributed()) {
                return $this->errorResponse(
                    'Cannot delete distributed gift',
                    400
                );
            }

            $gift->delete();

            return $this->successResponse(
                null,
                'Gift deleted successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Gift not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to delete gift: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Mark gift as distributed.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function markAsDistributed(Request $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $gift = Gift::findOrFail($id);

            // Check if already distributed
            if ($gift->isDistributed()) {
                return $this->errorResponse(
                    'Gift is already distributed',
                    400
                );
            }

            $gift->markAsDistributed($user->id, $request->notes);

            $gift->load(['user:id,full_name,employee_id', 'distributedBy:id,full_name']);

            return $this->successResponse(
                $gift,
                'Gift marked as distributed successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Gift not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to mark as distributed: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get gift statistics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $year = $request->get('year', now()->year);

            $statistics = Gift::getStatistics($year);

            return $this->successResponse(
                [
                    'year' => $year,
                    'statistics' => $statistics,
                ],
                'Gift statistics retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve statistics: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get member's gift history.
     *
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function memberHistory(Request $request, int $userId): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Members can only see their own history
            if ($user->isMember() && $user->id != $userId) {
                return $this->errorResponse('Access denied', 403);
            }

            $member = User::findOrFail($userId);

            $year = $request->get('year', now()->year);

            $gifts = Gift::where('user_id', $userId)
                ->byYear($year)
                ->where('status', 'distributed')
                ->orderBy('distribution_date', 'desc')
                ->get();

            $history = [
                'user' => $member->only(['id', 'full_name', 'employee_id']),
                'year' => $year,
                'total_value' => Gift::getMemberTotalForYear($userId, $year),
                'gifts_count' => $gifts->count(),
                'gifts' => $gifts->map(function($gift) {
                    return [
                        'id' => $gift->id,
                        'gift_type' => $gift->gift_type,
                        'type_name' => $gift->type_name,
                        'gift_name' => $gift->gift_name,
                        'gift_value' => $gift->gift_value,
                        'distribution_date' => $gift->distribution_date->format('Y-m-d'),
                        'notes' => $gift->notes,
                    ];
                }),
            ];

            return $this->successResponse(
                $history,
                'Member gift history retrieved successfully'
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

    /**
     * Get gifts by type.
     *
     * @param Request $request
     * @param string $type
     * @return JsonResponse
     */
    public function getByType(Request $request, string $type): JsonResponse
    {
        try {
            $validTypes = ['holiday', 'achievement', 'birthday', 'special_event', 'loyalty'];
            
            if (!in_array($type, $validTypes)) {
                return $this->errorResponse('Invalid gift type', 400);
            }

            $user = auth()->user();
            $query = Gift::with(['user:id,full_name,employee_id'])
                ->byType($type)
                ->where('status', 'distributed');

            // Access Control: Member only sees own gifts
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            // Filter by year
            if ($request->has('year')) {
                $query->byYear($request->year);
            }

            $gifts = $query->orderBy('distribution_date', 'desc')->get();

            return $this->successResponse(
                [
                    'type' => $type,
                    'type_name' => Gift::where('gift_type', $type)->first()?->type_name ?? $type,
                    'total_value' => $gifts->sum('gift_value'),
                    'gifts_count' => $gifts->count(),
                    'gifts' => $gifts,
                ],
                'Gifts by type retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve gifts: ' . $e->getMessage(),
                500
            );
        }
    }
}