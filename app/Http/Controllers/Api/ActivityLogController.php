<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of activity logs.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // Only admin/manager can view all logs
            if (!$user->isAdmin() && !$user->isManager()) {
                return $this->errorResponse('Access denied', 403);
            }

            $query = ActivityLog::with(['user:id,full_name,employee_id', 'cashAccount:id,code,name']);

            // Filter by user
            if ($request->has('user_id')) {
                $query->byUser($request->user_id);
            }

            // Filter by module
            if ($request->has('module')) {
                $query->byModule($request->module);
            }

            // Filter by activity
            if ($request->has('activity')) {
                $query->byActivity($request->activity);
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->dateRange($request->start_date, $request->end_date);
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('description', 'like', "%{$search}%")
                      ->orWhereHas('user', function($q2) use ($search) {
                          $q2->where('full_name', 'like', "%{$search}%");
                      });
                });
            }

            // Sort
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                $logs = $query->get();
                
                // Add computed attributes
                $logs->each(function($log) {
                    $log->activity_name = $log->activity_name;
                    $log->module_name = $log->module_name;
                });

                return $this->successResponse($logs, 'Activity logs retrieved successfully');
            } else {
                $logs = $query->paginate($perPage);
                
                // Add computed attributes
                $logs->getCollection()->each(function($log) {
                    $log->activity_name = $log->activity_name;
                    $log->module_name = $log->module_name;
                });

                return $this->paginatedResponse($logs, 'Activity logs retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve activity logs: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified activity log.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isAdmin() && !$user->isManager()) {
                return $this->errorResponse('Access denied', 403);
            }

            $log = ActivityLog::with(['user:id,full_name,employee_id,email', 'cashAccount:id,code,name'])
                ->findOrFail($id);

            // Add computed attributes
            $log->activity_name = $log->activity_name;
            $log->module_name = $log->module_name;

            return $this->successResponse(
                $log,
                'Activity log retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Activity log not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve activity log: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get user's activity history.
     *
     * @param int $userId
     * @return JsonResponse
     */
    public function userHistory(int $userId): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control
            if (!$user->isAdmin() && !$user->isManager()) {
                return $this->errorResponse('Access denied', 403);
            }

            $logs = ActivityLog::byUser($userId)
                ->with('cashAccount:id,code,name')
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get()
                ->map(function($log) {
                    return [
                        'id' => $log->id,
                        'activity' => $log->activity,
                        'activity_name' => $log->activity_name,
                        'module' => $log->module,
                        'module_name' => $log->module_name,
                        'description' => $log->description,
                        'created_at' => $log->created_at->format('d M Y H:i'),
                    ];
                });

            $targetUser = \App\Models\User::find($userId);

            return $this->successResponse(
                [
                    'user' => $targetUser ? $targetUser->only(['id', 'full_name', 'employee_id']) : null,
                    'logs' => $logs,
                ],
                'User activity history retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve user history: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get activity statistics.
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isAdmin() && !$user->isManager()) {
                return $this->errorResponse('Access denied', 403);
            }

            $stats = [
                'total_activities' => ActivityLog::count(),
                'today' => ActivityLog::whereDate('created_at', today())->count(),
                'this_week' => ActivityLog::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
                'this_month' => ActivityLog::whereMonth('created_at', now()->month)->count(),
                'by_activity' => [
                    'create' => ActivityLog::where('activity', 'create')->count(),
                    'update' => ActivityLog::where('activity', 'update')->count(),
                    'delete' => ActivityLog::where('activity', 'delete')->count(),
                    'login' => ActivityLog::where('activity', 'login')->count(),
                ],
                'by_module' => [
                    'savings' => ActivityLog::where('module', 'savings')->count(),
                    'loans' => ActivityLog::where('module', 'loans')->count(),
                    'installments' => ActivityLog::where('module', 'installments')->count(),
                    'users' => ActivityLog::where('module', 'users')->count(),
                    'gifts' => ActivityLog::where('module', 'gifts')->count(),
                ],
                'most_active_users' => ActivityLog::selectRaw('user_id, count(*) as total')
                    ->groupBy('user_id')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->with('user:id,full_name,employee_id')
                    ->get()
                    ->map(function($item) {
                        return [
                            'user' => $item->user,
                            'activity_count' => $item->total,
                        ];
                    }),
            ];

            return $this->successResponse(
                $stats,
                'Activity statistics retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve statistics: ' . $e->getMessage(),
                500
            );
        }
    }
}