<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\CashTransfer;
use App\Models\CashAccount;
use App\Http\Requests\CashTransferRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashTransferController extends Controller
{
    /**
     * Display a listing of cash transfers.
     * 
     * GET /api/cash-transfers
     */
    public function index(Request $request)
    {
        try {
            $query = CashTransfer::with(['fromCashAccount', 'toCashAccount', 'creator', 'approver']);
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Filter by cash account (from or to)
            if ($request->has('cash_account_id')) {
                $query->where(function($q) use ($request) {
                    $q->where('from_cash_account_id', $request->cash_account_id)
                      ->orWhere('to_cash_account_id', $request->cash_account_id);
                });
            }
            
            // Filter by date range
            if ($request->has('start_date')) {
                $query->whereDate('transfer_date', '>=', $request->start_date);
            }
            if ($request->has('end_date')) {
                $query->whereDate('transfer_date', '<=', $request->end_date);
            }
            
            // Search by transfer number
            if ($request->has('search')) {
                $query->where('transfer_number', 'like', '%' . $request->search . '%');
            }
            
            $transfers = $query->latest()->paginate(20);
            
            return response()->json([
                'success' => true,
                'message' => 'Data transfer kas berhasil diambil',
                'data' => $transfers
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Store a new cash transfer.
     * 
     * POST /api/cash-transfers
     */
    public function store(CashTransferRequest $request)
    {
        try {
            DB::beginTransaction();
            
            $createdBy = auth()->id();
            
            $transfer = CashTransfer::createTransfer($request->validated(), $createdBy);
            
            DB::commit();
            
            // Load relationships
            $transfer->load(['fromCashAccount', 'toCashAccount', 'creator']);
            
            return response()->json([
                'success' => true,
                'message' => 'Transfer kas berhasil dibuat. Menunggu approval.',
                'data' => $transfer
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat transfer: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified transfer.
     * 
     * GET /api/cash-transfers/{id}
     */
    public function show($id)
    {
        try {
            $transfer = CashTransfer::with(['fromCashAccount', 'toCashAccount', 'creator', 'approver', 'journal'])
                ->findOrFail($id);
            
            $summary = $transfer->getSummary();
            
            return response()->json([
                'success' => true,
                'message' => 'Detail transfer kas',
                'data' => [
                    'transfer' => $transfer,
                    'summary' => $summary
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 404);
        }
    }
    
    /**
     * Approve and complete a transfer.
     * 
     * POST /api/cash-transfers/{id}/approve
     */
    public function approve($id)
    {
        try {
            DB::beginTransaction();
            
            $transfer = CashTransfer::findOrFail($id);
            $approvedBy = auth()->id();
            
            // Check if already processed
            if (!$transfer->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transfer sudah diproses sebelumnya. Status: ' . $transfer->status_name
                ], 422);
            }
            
            $transfer->approveAndComplete($approvedBy);
            
            DB::commit();
            
            // Reload with relationships
            $transfer->load(['fromCashAccount', 'toCashAccount', 'approver', 'journal']);
            
            return response()->json([
                'success' => true,
                'message' => 'Transfer berhasil disetujui dan diproses',
                'data' => [
                    'transfer' => $transfer,
                    'summary' => $transfer->getSummary()
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal approve transfer: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Cancel a pending transfer.
     * 
     * POST /api/cash-transfers/{id}/cancel
     */
    public function cancel($id, Request $request)
    {
        try {
            DB::beginTransaction();
            
            $request->validate([
                'reason' => 'required|string|min:10|max:500'
            ], [
                'reason.required' => 'Alasan pembatalan harus diisi',
                'reason.min' => 'Alasan minimal 10 karakter',
                'reason.max' => 'Alasan maksimal 500 karakter',
            ]);
            
            $transfer = CashTransfer::findOrFail($id);
            $userId = auth()->id();
            
            $transfer->cancel($userId, $request->reason);
            
            DB::commit();
            
            // Reload with relationships
            $transfer->load(['fromCashAccount', 'toCashAccount', 'creator']);
            
            return response()->json([
                'success' => true,
                'message' => 'Transfer berhasil dibatalkan',
                'data' => $transfer
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan transfer: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get transfer statistics.
     * 
     * GET /api/cash-transfers/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $year = $request->get('year', date('Y'));
            $month = $request->get('month');
            
            $query = CashTransfer::whereYear('transfer_date', $year);
            
            if ($month) {
                $query->whereMonth('transfer_date', $month);
            }
            
            $stats = [
                'total_count' => $query->count(),
                'total_amount' => $query->sum('amount'),
                'by_status' => [
                    'pending' => $query->clone()->pending()->count(),
                    'completed' => $query->clone()->completed()->count(),
                    'cancelled' => $query->clone()->cancelled()->count(),
                ],
                'amount_by_status' => [
                    'pending' => $query->clone()->pending()->sum('amount'),
                    'completed' => $query->clone()->completed()->sum('amount'),
                    'cancelled' => $query->clone()->cancelled()->sum('amount'),
                ],
            ];
            
            // Top 5 most transferred from/to accounts
            $topFrom = CashTransfer::selectRaw('from_cash_account_id, COUNT(*) as count, SUM(amount) as total')
                ->whereYear('transfer_date', $year)
                ->completed()
                ->groupBy('from_cash_account_id')
                ->orderByDesc('total')
                ->limit(5)
                ->with('fromCashAccount')
                ->get();
            
            $topTo = CashTransfer::selectRaw('to_cash_account_id, COUNT(*) as count, SUM(amount) as total')
                ->whereYear('transfer_date', $year)
                ->completed()
                ->groupBy('to_cash_account_id')
                ->orderByDesc('total')
                ->limit(5)
                ->with('toCashAccount')
                ->get();
            
            return response()->json([
                'success' => true,
                'message' => 'Statistik transfer kas',
                'data' => [
                    'summary' => $stats,
                    'top_from_accounts' => $topFrom,
                    'top_to_accounts' => $topTo,
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik: ' . $e->getMessage()
            ], 500);
        }
    }
}