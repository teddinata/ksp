<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\MemberResignation;
use App\Models\User;
use App\Models\ActivityLog;
use App\Http\Requests\MemberResignationRequest;
use App\Http\Requests\ApproveResignationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberResignationController extends Controller
{
    /**
     * Display a listing of resignation requests.
     * 
     * GET /api/resignations
     */
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $query = MemberResignation::with(['user', 'processedBy']);

            // Access Control: Members can only see their own requests
            if ($user->isMember()) {
                $query->where('user_id', $user->id);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by user
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            // Filter by date range
            if ($request->has('start_date')) {
                $query->whereDate('resignation_date', '>=', $request->start_date);
            }
            if ($request->has('end_date')) {
                $query->whereDate('resignation_date', '<=', $request->end_date);
            }

            // Search by member name
            if ($request->has('search')) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('full_name', 'like', '%' . $request->search . '%')
                        ->orWhere('employee_id', 'like', '%' . $request->search . '%');
                });
            }

            $resignations = $query->latest()->paginate(20);

            return response()->json([
                'success' => true,
                'message' => 'Data pengajuan keluar berhasil diambil',
                'data' => $resignations
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new resignation request.
     * 
     * POST /api/resignations
     */
    public function store(MemberResignationRequest $request)
    {
        try {
            $authUser = auth()->user();
            $targetUserId = (int)$request->user_id;

            // Access Control: Members can only submit for themselves
            if ($authUser->isMember() && $authUser->id !== $targetUserId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anggota hanya dapat mengajukan pengunduran diri untuk diri sendiri'
                ], 403);
            }

            DB::beginTransaction();

            $user = User::findOrFail($targetUserId);

            // Create resignation using model method
            $resignation = MemberResignation::createRequest(
                $user, // kirim object User, bukan $user->id
                $request->reason // hanya 2 parameter
            );

            DB::commit();

            // Load relationships
            $resignation->load(['user', 'processedBy']);

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan keluar berhasil dibuat',
                'data' => $resignation
            ], 201);

        }
        catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat pengajuan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resignation.
     * 
     * GET /api/resignations/{id}
     */
    public function show($id)
    {
        try {
            $user = auth()->user();
            $resignation = MemberResignation::with(['user', 'processedBy', 'withdrawal'])
                ->findOrFail($id);

            // Access Control: Members can only view their own resignation
            if ($user->isMember() && $resignation->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak'
                ], 403);
            }

            // Get summary
            $summary = $resignation->getSummary();

            return response()->json([
                'success' => true,
                'message' => 'Detail pengajuan keluar',
                'data' => [
                    'resignation' => $resignation,
                    'summary' => $summary
                ]
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 404);
        }
    }

    /**
     * Approve or reject resignation request.
     * 
     * POST /api/resignations/{id}/process
     */
    public function process($id, ApproveResignationRequest $request)
    {
        try {
            DB::beginTransaction();

            $resignation = MemberResignation::findOrFail($id);
            $adminId = auth()->id();

            if ($request->action === 'approve') {
                $resignation->approve($adminId);
                $message = 'Pengajuan keluar berhasil disetujui';
            }
            else {
                $resignation->reject($adminId, $request->rejection_reason);
                $message = 'Pengajuan keluar ditolak';
            }

            DB::commit();

            // Reload with relationships
            $resignation->load(['user', 'processedBy']);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $resignation
            ]);

        }
        catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get resignation statistics.
     * 
     * GET /api/resignations/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $user = auth()->user();

            // Access Control: Admin and Manager only
            if ($user->isMember()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak'
                ], 403);
            }

            $year = $request->get('year', date('Y'));

            $stats = [
                'total' => MemberResignation::whereYear('created_at', $year)->count(),
                'pending' => MemberResignation::pending()->whereYear('created_at', $year)->count(),
                'approved' => MemberResignation::approved()->whereYear('created_at', $year)->count(),
                'completed' => MemberResignation::completed()->whereYear('created_at', $year)->count(),
                'rejected' => MemberResignation::rejected()->whereYear('created_at', $year)->count(),
            ];

            // Monthly breakdown
            $monthly = [];
            for ($month = 1; $month <= 12; $month++) {
                $monthly[] = [
                    'month' => $month,
                    'month_name' => date('F', mktime(0, 0, 0, $month, 1)),
                    'count' => MemberResignation::whereYear('created_at', $year)
                    ->whereMonth('created_at', $month)
                    ->count()
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Statistik pengajuan keluar',
                'data' => [
                    'summary' => $stats,
                    'monthly' => $monthly
                ]
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get member's resignation history.
     * 
     * GET /api/members/{userId}/resignations
     */
    public function memberHistory($userId)
    {
        try {
            $user = auth()->user();

            // Access Control: Members can only see their own history
            if ($user->isMember() && $user->id != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak'
                ], 403);
            }

            $resignations = MemberResignation::where('user_id', $userId)
                ->with(['processedBy'])
                ->latest()
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Riwayat pengajuan keluar member',
                'data' => $resignations
            ]);

        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
}