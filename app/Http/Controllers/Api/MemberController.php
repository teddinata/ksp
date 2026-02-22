<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Saving;
use App\Models\Loan;
use App\Models\Installment;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of members.
     * 
     * Business Logic:
     * - Admin/Manager: Can see all members
     * - Member: Can only see their own profile (redirected to show)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Members cannot list all members
            if ($user->isMember()) {
                return $this->errorResponse(
                    'Members can only view their own profile. Use GET /members/profile endpoint.',
                    403
                );
            }

            $query = User::query()->members();

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Search by name, email, or employee ID
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('employee_id', 'like', "%{$search}%")
                      ->orWhere('phone_number', 'like', "%{$search}%");
                });
            }

            // Filter by join date range
            if ($request->has('joined_from') && $request->has('joined_to')) {
                $query->whereBetween('joined_at', [
                    $request->joined_from,
                    $request->joined_to
                ]);
            }

            // Sort
            $sortBy = $request->get('sort_by', 'joined_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                $members = $query->get();
                
                // Add computed attributes
                $members->each(function($member) {
                    $member->membership_duration = $member->membership_duration;
                    $member->initials = $member->initials;
                });

                return $this->successResponse($members, 'Members retrieved successfully');
            } else {
                $members = $query->paginate($perPage);
                
                // Add computed attributes
                $members->getCollection()->each(function($member) {
                    $member->membership_duration = $member->membership_duration;
                    $member->initials = $member->initials;
                });

                return $this->paginatedResponse($members, 'Members retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve members: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display a listing of management users (Admin/Manager).
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function managementIndex(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Only Admin/Manager can see this list
            if ($user->isMember()) {
                return $this->errorResponse('Access denied', 403);
            }

            $query = User::query()->whereIn('role', ['admin', 'manager']);

            // Search by name, email, or employee ID
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('employee_id', 'like', "%{$search}%");
                });
            }

            // Filter by role
            if ($request->has('role')) {
                $query->where('role', $request->role);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            $staff = $query->paginate($perPage);

            return $this->paginatedResponse($staff, 'Management users retrieved successfully');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve management users: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get member profile.
     * 
     * Returns own profile for members, or specific member for admin/manager.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // Members can only see their own profile
            $targetUserId = $user->isMember() ? $user->id : $request->get('user_id', $user->id);

            $member = User::findOrFail($targetUserId);

            // Add computed attributes
            $profile = [
                'id' => $member->id,
                'employee_id' => $member->employee_id,
                'full_name' => $member->full_name,
                'email' => $member->email,
                'phone_number' => $member->phone_number,
                'formatted_phone' => $member->formatted_phone,
                'address' => $member->address,
                'role' => $member->role,
                'status' => $member->status,
                'joined_at' => $member->joined_at?->format('Y-m-d'),
                'membership_duration' => $member->membership_duration,
                'membership_status' => $member->membership_status,
                'initials' => $member->initials,
                'created_at' => $member->created_at,
                'updated_at' => $member->updated_at,
            ];

            return $this->successResponse(
                $profile,
                'Profile retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Member not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve profile: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified member.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Members can only see their own details
            if ($user->isMember() && $user->id != $id) {
                return $this->errorResponse('Access denied', 403);
            }

            $member = User::findOrFail($id);

            // Access Control: Only admin/manager can see non-member profiles (staff)
            if (!$member->isMember() && $user->isMember()) {
                return $this->errorResponse('Hanya admin dan manager yang dapat melihat profil staff', 403);
            }

            // Full member details
            $details = [
                'profile' => [
                    'id' => $member->id,
                    'employee_id' => $member->employee_id,
                    'full_name' => $member->full_name,
                    'email' => $member->email,
                    'phone_number' => $member->phone_number,
                    'formatted_phone' => $member->formatted_phone,
                    'address' => $member->address,
                    'role' => $member->role,
                    'status' => $member->status,
                    'joined_at' => $member->joined_at?->format('Y-m-d'),
                    'membership_duration' => $member->membership_duration . ' months',
                    'membership_status' => $member->membership_status,
                    'initials' => $member->initials,
                ],
                'financial_summary' => $member->getFinancialSummary(),
                'statistics' => [
                    'total_savings_transactions' => $member->savings()->count(),
                    'total_loans' => $member->loans()->count(),
                    'active_loans' => $member->active_loans->count(),
                    'completed_loans' => $member->loans()->where('status', 'paid_off')->count(),
                ],
            ];

            return $this->successResponse(
                $details,
                'Member details retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Member not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve member: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update member profile.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Members can only update their own profile
            if ($user->isMember() && $user->id != $id) {
                return $this->errorResponse('Hanya dapat mengubah profil sendiri', 403);
            }

            $member = User::findOrFail($id);

            // Validation
            $validated = $request->validate([
                'full_name' => 'sometimes|string|max:255',
                'employee_id' => [
                    'sometimes',
                    'string',
                    Rule::unique('users')->ignore($member->id)
                ],
                'email' => [
                    'sometimes',
                    'email',
                    Rule::unique('users')->ignore($member->id)
                ],
                'phone_number' => 'sometimes|string|max:20',
                'address' => 'sometimes|string',
                'status' => [
                    'sometimes',
                    Rule::in(['active', 'inactive', 'suspended'])
                ],
            ]);

            // Only admin can change status
            if (isset($validated['status']) && !$user->isAdmin()) {
                return $this->errorResponse(
                    'Hanya admin yang dapat mengubah status',
                    403
                );
            }

            // Only admin or manager can change employee_id
            if (isset($validated['employee_id']) && $validated['employee_id'] !== $member->employee_id) {
                if (!$user->isAdmin() && !$user->isManager()) {
                    return $this->errorResponse(
                        'Hanya admin dan manager yang dapat mengubah ID anggota',
                        403
                    );
                }
            }

            $member->update($validated);

            return $this->successResponse(
                $member,
                'Profil anggota berhasil diperbarui'
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                'Validasi gagal',
                422,
                $e->errors()
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Member not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memperbarui profil: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Change member password.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function changePassword(Request $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Members can only change their own password
            if ($user->isMember() && $user->id != $id) {
                return $this->errorResponse('Hanya anggota yang dapat mengubah password', 403);
            }

            $member = User::findOrFail($id);

            // Validation
            $validated = $request->validate([
                'current_password' => 'required_if:self,true|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);

            // If user is changing their own password, verify current password
            if ($user->id == $id) {
                if (!Hash::check($validated['current_password'], $member->password)) {
                    return $this->errorResponse(
                        'Current password is incorrect',
                        400
                    );
                }
            }

            $member->update([
                'password' => Hash::make($validated['new_password'])
            ]);

            return $this->successResponse(
                null,
                'Password changed successfully'
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $e->errors()
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Member not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to change password: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get member financial summary.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function financialSummary(int $id): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control
            if ($user->isMember() && $user->id != $id) {
                return $this->errorResponse('Access denied', 403);
            }

            $member = User::findOrFail($id);

            $summary = $member->getFinancialSummary();

            return $this->successResponse(
                $summary,
                'Financial summary retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Member not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve financial summary: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get member activity history.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function activityHistory(Request $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control
            if ($user->isMember() && $user->id != $id) {
                return $this->errorResponse('Access denied', 403);
            }

            $member = User::findOrFail($id);

            $limit = $request->get('limit', 20);

            // Get recent savings
            $recentSavings = $member->savings()
                ->with('cashAccount:id,code,name')
                ->latest('transaction_date')
                ->limit($limit)
                ->get()
                ->map(function($saving) {
                    return [
                        'type' => 'saving',
                        'id' => $saving->id,
                        'date' => $saving->transaction_date,
                        'description' => $saving->type_name . ' - ' . $saving->cash_account->name,
                        'amount' => $saving->final_amount,
                        'status' => $saving->status,
                    ];
                });

            // Get recent loan activities
            $recentLoans = $member->loans()
                ->with('cashAccount:id,code,name')
                ->latest('application_date')
                ->limit($limit)
                ->get()
                ->map(function($loan) {
                    return [
                        'type' => 'loan',
                        'id' => $loan->id,
                        'date' => $loan->application_date,
                        'description' => 'Pinjaman ' . $loan->loan_number . ' - ' . $loan->cash_account->name,
                        'amount' => $loan->principal_amount,
                        'status' => $loan->status,
                    ];
                });

            // Merge and sort
            $activities = $recentSavings->concat($recentLoans)
                ->sortByDesc('date')
                ->take($limit)
                ->values();

            return $this->successResponse(
                $activities,
                'Activity history retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Member not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve activity history: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get member statistics.
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Admin/Manager only
            if ($user->isMember()) {
                return $this->errorResponse('Access denied', 403);
            }

            $stats = [
                'total_members' => User::members()->count(),
                'active_members' => User::members()->active()->count(),
                'inactive_members' => User::members()->inactive()->count(),
                'suspended_members' => User::members()->suspended()->count(),
                'new_members_this_month' => User::members()
                    ->whereMonth('joined_at', now()->month)
                    ->whereYear('joined_at', now()->year)
                    ->count(),
                'members_with_active_loans' => User::members()
                    ->whereHas('loans', function($q) {
                        $q->whereIn('status', ['disbursed', 'active']);
                    })
                    ->count(),
                'members_with_overdue_payments' => User::members()
                    ->whereHas('loans.installments', function($q) {
                        $q->where('status', 'overdue');
                    })
                    ->count(),
            ];

            return $this->successResponse(
                $stats,
                'Member statistics retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve statistics: ' . $e->getMessage(),
                500
            );
        }
    }

    // =====================================================
    // UPDATED: MemberController::store() method
    // =====================================================

    /**
     * Store a new member.
     * 
     * ✅ UPDATED: Now supports role selection (anggota, manager, admin)
     * 
     * Business Logic:
     * - Admin can create users with any role
     * - Manager can only create members (anggota)
     * - Auto-generate employee_id based on role if not provided
     * - Default status: active
     * - Password is required and will be hashed
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Admin and Manager only
            if ($user->isMember()) {
                return $this->errorResponse(
                    'Only administrators and managers can create new users',
                    403
                );
            }

            // Validation
            $validated = $request->validate([
                'full_name' => 'required|string|max:255',
                'employee_id' => 'sometimes|string|unique:users,employee_id',
                'email' => 'nullable|email|unique:users,email',
                'password' => 'required|string|min:8|confirmed',
                'phone_number' => 'nullable|string|max:20',
                'address' => 'nullable|string',
                'work_unit' => 'nullable|string|max:255',
                'position' => 'nullable|string|max:255',
                'joined_at' => 'nullable|date',
                'status' => 'sometimes|in:active,inactive',
                'role' => 'sometimes|in:anggota,manager,admin',  // ✅ NEW: Role selection
            ]);

            // ✅ Access Control: Only admin can create manager/admin
            $requestedRole = $validated['role'] ?? 'anggota';
            
            if ($user->isManager() && $requestedRole !== 'anggota') {
                return $this->errorResponse(
                    'Managers can only create member (anggota) accounts',
                    403
                );
            }

            // ✅ Auto-generate employee_id based on role if not provided
            if (!isset($validated['employee_id'])) {
                $validated['employee_id'] = match($requestedRole) {
                    'admin' => 'ADM' . now()->format('Ymd') . str_pad(
                        User::where('role', 'admin')
                            ->whereDate('created_at', today())
                            ->where('employee_id', 'like', 'ADM' . now()->format('Ymd') . '%')
                            ->count() + 1,
                        3,
                        '0',
                        STR_PAD_LEFT
                    ),
                    'manager' => 'MGR' . now()->format('Ymd') . str_pad(
                        User::where('role', 'manager')
                            ->whereDate('created_at', today())
                            ->where('employee_id', 'like', 'MGR' . now()->format('Ymd') . '%')
                            ->count() + 1,
                        3,
                        '0',
                        STR_PAD_LEFT
                    ),
                    default => 'EMP' . now()->format('Ymd') . str_pad(
                        User::members()
                            ->whereDate('created_at', today())
                            ->where('employee_id', 'like', 'EMP' . now()->format('Ymd') . '%')
                            ->count() + 1,
                        3,
                        '0',
                        STR_PAD_LEFT
                    ),
                };
            }

            // Create user
            $newUser = User::create([
                'full_name' => $validated['full_name'],
                'employee_id' => $validated['employee_id'],
                'email' => $validated['email'] ?? null,
                'password' => Hash::make($validated['password']),
                'phone_number' => $validated['phone_number'] ?? null,
                'address' => $validated['address'] ?? null,
                'work_unit' => $validated['work_unit'] ?? null,
                'position' => $validated['position'] ?? null,
                'role' => $requestedRole,  // ✅ Use selected role
                'status' => $validated['status'] ?? 'active',
                'joined_at' => $validated['joined_at'] ?? now()->toDateString(),
            ]);

            // ✅ Log creation based on role
            \Log::info('New user created', [
                'created_by' => $user->id,
                'new_user_id' => $newUser->id,
                'role' => $requestedRole,
            ]);

            // Add computed attributes
            $userData = [
                'id' => $newUser->id,
                'employee_id' => $newUser->employee_id,
                'full_name' => $newUser->full_name,
                'email' => $newUser->email,
                'phone_number' => $newUser->phone_number,
                'formatted_phone' => $newUser->formatted_phone,
                'address' => $newUser->address,
                'work_unit' => $newUser->work_unit,
                'position' => $newUser->position,
                'role' => $newUser->role,  // ✅ Show role
                'status' => $newUser->status,
                'joined_at' => $newUser->joined_at?->format('Y-m-d'),
                'membership_duration' => $newUser->membership_duration,
                'membership_status' => $newUser->membership_status,
                'initials' => $newUser->initials,
                'created_at' => $newUser->created_at,
                'updated_at' => $newUser->updated_at,
            ];

            $message = match($requestedRole) {
                'admin' => 'Admin user created successfully',
                'manager' => 'Manager user created successfully',
                default => 'Member created successfully',
            };

            return $this->successResponse(
                $userData,
                $message,
                201
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $e->errors()
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create user: ' . $e->getMessage(),
                500
            );
        }
    }


    /**
     * Suspend or activate member.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control: Admin only
            if (!$user->isAdmin()) {
                return $this->errorResponse('Only administrators can change member status', 403);
            }

            $member = User::findOrFail($id);

            if (!$member->isMember()) {
                return $this->errorResponse('User is not a member', 400);
            }

            $validated = $request->validate([
                'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
                'reason' => 'required_if:status,suspended|string',
            ]);

            $member->update(['status' => $validated['status']]);

            $message = match($validated['status']) {
                'active' => 'Member activated successfully',
                'inactive' => 'Member deactivated successfully',
                'suspended' => 'Member suspended successfully',
            };

            return $this->successResponse($member, $message);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $e->errors()
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Member not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update status: ' . $e->getMessage(),
                500
            );
        }
    }
}