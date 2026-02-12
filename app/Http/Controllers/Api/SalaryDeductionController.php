<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\SalaryDeduction;
use App\Models\User;
use App\Http\Requests\SalaryDeductionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SalaryDeductionController extends Controller
{
    /**
     * Display a listing of salary deductions.
     * 
     * GET /api/salary-deductions
     */
    public function index(Request $request)
    {
        try {
            $query = SalaryDeduction::with(['user', 'processedBy']);
            
            // Filter by user
            if ($request->has('user_id')) {
                $query->byUser($request->user_id);
            }
            
            // Filter by period
            if ($request->has('period_month') && $request->has('period_year')) {
                $query->byPeriod($request->period_month, $request->period_year);
            } elseif ($request->has('period_year')) {
                $query->byYear($request->period_year);
            }
            
            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            $deductions = $query->latest()->paginate(20);
            
            return response()->json([
                'success' => true,
                'message' => 'Data potongan gaji berhasil diambil',
                'data' => $deductions
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Process salary deduction for a member.
     * 
     * POST /api/salary-deductions
     */
    public function store(SalaryDeductionRequest $request)
    {
        try {
            DB::beginTransaction();
            
            $member = User::findOrFail($request->user_id);
            $processedBy = auth()->id();
            
            $options = [
                'savings_deduction' => $request->savings_deduction ?? 0,
                'other_deductions' => $request->other_deductions ?? 0,
                'notes' => $request->notes,
            ];
            
            // Process using model method
            $salaryDeduction = SalaryDeduction::processForMember(
                $member,
                $request->period_month,
                $request->period_year,
                $request->gross_salary,
                $processedBy,
                $options
            );
            
            DB::commit();
            
            // Load relationships
            $salaryDeduction->load(['user', 'processedBy']);
            
            return response()->json([
                'success' => true,
                'message' => 'Potongan gaji berhasil diproses',
                'data' => [
                    'salary_deduction' => $salaryDeduction,
                    'breakdown' => $salaryDeduction->getBreakdown()
                ]
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses potongan gaji: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified salary deduction.
     * 
     * GET /api/salary-deductions/{id}
     */
    public function show($id)
    {
        try {
            $deduction = SalaryDeduction::with(['user', 'processedBy', 'journal'])
                ->findOrFail($id);
            
            $breakdown = $deduction->getBreakdown();
            
            return response()->json([
                'success' => true,
                'message' => 'Detail potongan gaji',
                'data' => [
                    'salary_deduction' => $deduction,
                    'breakdown' => $breakdown
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
     * Get salary deduction by period.
     * 
     * GET /api/salary-deductions/period/{year}/{month}
     */
    public function byPeriod($year, $month)
    {
        try {
            $deductions = SalaryDeduction::with(['user', 'processedBy'])
                ->byPeriod($month, $year)
                ->get();
            
            $totals = SalaryDeduction::getTotalForPeriod($month, $year);
            
            return response()->json([
                'success' => true,
                'message' => 'Potongan gaji periode ' . Carbon::create($year, $month, 1)->format('F Y'),
                'data' => [
                    'deductions' => $deductions,
                    'totals' => $totals
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get member's annual summary.
     * 
     * GET /api/members/{userId}/salary-deductions/annual/{year}
     */
    public function memberAnnualSummary($userId, $year)
    {
        try {
            $summary = SalaryDeduction::getMemberAnnualSummary($userId, $year);
            
            return response()->json([
                'success' => true,
                'message' => 'Ringkasan potongan gaji tahunan',
                'data' => $summary
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Batch process salary deductions for multiple members.
     * 
     * POST /api/salary-deductions/batch
     */
    public function batchProcess(Request $request)
    {
        try {
            $request->validate([
                'period_month' => 'required|integer|min:1|max:12',
                'period_year' => 'required|integer|min:2020|max:2100',
                'members' => 'required|array|min:1',
                'members.*.user_id' => 'required|exists:users,id',
                'members.*.gross_salary' => 'required|numeric|min:0',
                'members.*.savings_deduction' => 'nullable|numeric|min:0',
                'members.*.other_deductions' => 'nullable|numeric|min:0',
                'members.*.notes' => 'nullable|string|max:1000',
            ]);
            
            DB::beginTransaction();
            
            $processedBy = auth()->id();
            $results = [
                'success' => [],
                'failed' => []
            ];
            
            foreach ($request->members as $memberData) {
                try {
                    $member = User::findOrFail($memberData['user_id']);
                    
                    $options = [
                        'savings_deduction' => $memberData['savings_deduction'] ?? 0,
                        'other_deductions' => $memberData['other_deductions'] ?? 0,
                        'notes' => $memberData['notes'] ?? null,
                    ];
                    
                    $deduction = SalaryDeduction::processForMember(
                        $member,
                        $request->period_month,
                        $request->period_year,
                        $memberData['gross_salary'],
                        $processedBy,
                        $options
                    );
                    
                    $results['success'][] = [
                        'user_id' => $member->id,
                        'full_name' => $member->full_name,
                        'deduction_id' => $deduction->id
                    ];
                    
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'user_id' => $memberData['user_id'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Proses batch selesai',
                'data' => [
                    'total' => count($request->members),
                    'success_count' => count($results['success']),
                    'failed_count' => count($results['failed']),
                    'results' => $results
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses batch: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get statistics for salary deductions.
     * 
     * GET /api/salary-deductions/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $year = $request->get('year', date('Y'));
            
            $query = SalaryDeduction::whereYear('created_at', $year);
            
            $stats = [
                'total_members' => $query->distinct('user_id')->count('user_id'),
                'total_processed' => $query->count(),
                'total_gross_salary' => $query->sum('gross_salary'),
                'total_loan_deduction' => $query->sum('loan_deduction'),
                'total_savings_deduction' => $query->sum('savings_deduction'),
                'total_other_deduction' => $query->sum('other_deductions'),
                'total_deductions' => $query->sum('total_deductions'),
                'total_net_salary' => $query->sum('net_salary'),
            ];
            
            // Monthly breakdown
            $monthly = [];
            for ($month = 1; $month <= 12; $month++) {
                $monthlyData = SalaryDeduction::getTotalForPeriod($month, $year);
                $monthly[] = [
                    'month' => $month,
                    'month_name' => Carbon::create($year, $month, 1)->format('F'),
                    'data' => $monthlyData
                ];
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Statistik potongan gaji',
                'data' => [
                    'summary' => $stats,
                    'monthly' => $monthly
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
    * Get authenticated member's salary deductions.
    * 
    * GET /api/salary-deductions/my-deductions
    */
    public function myDeductions(Request $request)
    {
        try {
            $user = auth()->user();
            
            $query = SalaryDeduction::with(['processedBy', 'journal'])
                ->byUser($user->id);
            
            // Filter by period
            if ($request->has('period_month') && $request->has('period_year')) {
                $query->byPeriod($request->period_month, $request->period_year);
            } elseif ($request->has('period_year')) {
                $query->byYear($request->period_year);
            }
            
            $deductions = $query->latest()->paginate(20);
            
            return response()->json([
                'success' => true,
                'message' => 'Data potongan gaji Anda berhasil diambil',
                'data' => $deductions
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
}