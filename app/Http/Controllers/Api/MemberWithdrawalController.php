<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\MemberResignation;
use App\Models\MemberWithdrawal;
use App\Models\CashAccount;
use App\Http\Requests\MemberWithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberWithdrawalController extends Controller
{
    /**
     * Display a listing of withdrawals.
     * 
     * GET /api/withdrawals
     */
    public function index(Request $request)
    {
        try {
            $query = MemberWithdrawal::with(['user', 'resignation', 'cashAccount', 'processedBy']);
            
            // Filter by payment method
            if ($request->has('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }
            
            // Filter by user
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            
            // Filter by date range
            if ($request->has('start_date')) {
                $query->whereDate('withdrawal_date', '>=', $request->start_date);
            }
            if ($request->has('end_date')) {
                $query->whereDate('withdrawal_date', '<=', $request->end_date);
            }
            
            $withdrawals = $query->latest()->paginate(20);
            
            return response()->json([
                'success' => true,
                'message' => 'Data pencairan berhasil diambil',
                'data' => $withdrawals
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Process withdrawal for an approved resignation.
     * 
     * POST /api/resignations/{resignationId}/withdraw
     */
    public function process($resignationId, MemberWithdrawalRequest $request)
    {
        try {
            DB::beginTransaction();
            
            // Get resignation
            $resignation = MemberResignation::findOrFail($resignationId);
            
            // Validate resignation is approved
            if (!$resignation->isApproved()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pengajuan keluar belum disetujui. Status: ' . $resignation->status_name
                ], 422);
            }
            
            // Check if already withdrawn
            if ($resignation->withdrawal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pencairan sudah dilakukan sebelumnya'
                ], 422);
            }
            
            $cashAccount = CashAccount::findOrFail($request->cash_account_id);
            $processedBy = auth()->id();
            
            // Prepare withdrawal data
            $withdrawalData = [
                'cash_account_id' => $request->cash_account_id,
                'payment_method' => $request->payment_method,
                'notes' => $request->notes,
            ];
            
            // Add payment method specific fields
            if ($request->payment_method === 'transfer') {
                $withdrawalData['bank_name'] = $request->bank_name;
                $withdrawalData['account_number'] = $request->account_number;
                $withdrawalData['account_holder_name'] = $request->account_holder_name;
                $withdrawalData['transfer_reference'] = $request->transfer_reference;
            } elseif ($request->payment_method === 'check') {
                $withdrawalData['check_number'] = $request->check_number;
                $withdrawalData['check_date'] = $request->check_date;
            }
            
            // Process withdrawal using model method
            $withdrawal = MemberWithdrawal::processWithdrawal(
                $resignation,
                $cashAccount,
                $withdrawalData,
                $processedBy
            );
            
            DB::commit();
            
            // Reload with relationships
            $withdrawal->load(['user', 'resignation', 'cashAccount', 'processedBy']);
            
            return response()->json([
                'success' => true,
                'message' => 'Pencairan simpanan berhasil diproses',
                'data' => [
                    'withdrawal' => $withdrawal,
                    'summary' => $withdrawal->getSummary()
                ]
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses pencairan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified withdrawal.
     * 
     * GET /api/withdrawals/{id}
     */
    public function show($id)
    {
        try {
            $withdrawal = MemberWithdrawal::with(['user', 'resignation', 'cashAccount', 'processedBy'])
                ->findOrFail($id);
            
            $summary = $withdrawal->getSummary();
            
            return response()->json([
                'success' => true,
                'message' => 'Detail pencairan',
                'data' => [
                    'withdrawal' => $withdrawal,
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
     * Get withdrawal statistics.
     * 
     * GET /api/withdrawals/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $year = $request->get('year', date('Y'));
            $month = $request->get('month');
            
            $query = MemberWithdrawal::whereYear('withdrawal_date', $year);
            
            if ($month) {
                $query->whereMonth('withdrawal_date', $month);
            }
            
            $stats = [
                'total_count' => $query->count(),
                'total_amount' => $query->sum('total_amount'),
                'by_payment_method' => [
                    'cash' => $query->clone()->where('payment_method', 'cash')->count(),
                    'transfer' => $query->clone()->where('payment_method', 'transfer')->count(),
                    'check' => $query->clone()->where('payment_method', 'check')->count(),
                ],
                'amount_by_payment_method' => [
                    'cash' => $query->clone()->where('payment_method', 'cash')->sum('total_amount'),
                    'transfer' => $query->clone()->where('payment_method', 'transfer')->sum('total_amount'),
                    'check' => $query->clone()->where('payment_method', 'check')->sum('total_amount'),
                ],
            ];
            
            return response()->json([
                'success' => true,
                'message' => 'Statistik pencairan',
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik: ' . $e->getMessage()
            ], 500);
        }
    }
}