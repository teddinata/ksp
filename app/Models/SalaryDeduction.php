<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SalaryDeduction extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'salary_deductions';

    protected $fillable = [
        'period_month',
        'period_year',
        'user_id',
        'gross_salary',
        'loan_deduction',
        'savings_deduction',
        'other_deductions',
        'total_deductions',
        'net_salary',
        'deduction_date',
        'processed_by',
        'status',
        'notes',
        'journal_id',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'integer',
            'period_year' => 'integer',
            'gross_salary' => 'decimal:2',
            'loan_deduction' => 'decimal:2',
            'savings_deduction' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'deduction_date' => 'date',
        ];
    }

    // ==================== RELATIONSHIPS ====================

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByPeriod($query, int $month, int $year)
    {
        return $query->where('period_month', $month)
                     ->where('period_year', $year);
    }

    public function scopeByYear($query, int $year)
    {
        return $query->where('period_year', $year);
    }

    // ==================== ACCESSORS ====================

    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Menunggu Proses',
            'processed' => 'Sudah Diproses',
            'paid' => 'Sudah Dibayar',
            'cancelled' => 'Dibatalkan',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'processed' => 'info',
            'paid' => 'success',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }

    public function getPeriodDisplayAttribute(): string
    {
        return Carbon::create($this->period_year, $this->period_month, 1)
            ->format('F Y');
    }

    public function getPeriodShortAttribute(): string
    {
        return str_pad($this->period_month, 2, '0', STR_PAD_LEFT) . '/' . $this->period_year;
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Process salary deduction for a member.
     * 
     * BUSINESS LOGIC:
     * 1. Calculate loan deductions from active loans with deduction_method = 'salary'
     * 2. Calculate mandatory savings deduction
     * 3. Calculate other deductions
     * 4. Auto-pay installments
     * 5. Create journal entry
     * 
     * @param User $member
     * @param int $month
     * @param int $year
     * @param float $grossSalary
     * @param int $processedBy
     * @param array $options
     * @return self
     */
    public static function processForMember(
        User $member,
        int $month,
        int $year,
        float $grossSalary,
        int $processedBy,
        array $options = []
    ): self {
        // Check if already exists
        $existing = self::where('user_id', $member->id)
            ->byPeriod($month, $year)
            ->first();
            
        if ($existing) {
            throw new \Exception('Potongan gaji untuk member ini di periode ini sudah ada');
        }

        DB::beginTransaction();
        
        try {
            // Get installments due this period
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth();
            
            // Get loans with salary deduction method
            $loansWithSalaryDeduction = Loan::where('user_id', $member->id)
                ->whereIn('status', ['disbursed', 'active'])
                ->where(function($q) {
                    $q->where('deduction_method', 'salary')
                      ->orWhere('deduction_method', 'mixed');
                })
                ->get();
            
            $totalLoanDeduction = 0;
            $installmentsPaid = [];
            
            // Process each loan
            foreach ($loansWithSalaryDeduction as $loan) {
                // Get installments for this period
                $installments = Installment::where('loan_id', $loan->id)
                    ->where('status', 'pending')
                    ->whereBetween('due_date', [$startDate, $endDate])
                    ->orderBy('installment_number')
                    ->get();
                
                foreach ($installments as $installment) {
                    // Calculate deduction amount based on percentage
                    if ($loan->deduction_method === 'salary') {
                        $deductionAmount = $installment->total_amount;
                    } else { // mixed
                        $percentage = $loan->salary_deduction_percentage / 100;
                        $deductionAmount = $installment->total_amount * $percentage;
                    }
                    
                    $totalLoanDeduction += $deductionAmount;
                    
                    // Mark installment as paid
                    $installment->update([
                        'status' => 'auto_paid',
                        'payment_date' => now(),
                        'payment_method' => 'salary',
                        'paid_amount' => $deductionAmount,
                        'notes' => "Dibayar via potong gaji periode {$startDate->format('F Y')}",
                    ]);
                    
                    // Update loan remaining principal
                    $loan->remaining_principal -= $installment->principal_amount;
                    $loan->save();
                    
                    // Check if loan is paid off
                    if ($loan->remaining_principal <= 0) {
                        $loan->update(['status' => 'paid_off']);
                    }
                    
                    $installmentsPaid[] = $installment;
                }
            }
            
            // Calculate mandatory savings deduction
            $savingsDeduction = $options['savings_deduction'] ?? 0;
            
            // Calculate other deductions
            $otherDeductions = $options['other_deductions'] ?? 0;
            
            // Calculate totals
            $totalDeductions = $totalLoanDeduction + $savingsDeduction + $otherDeductions;
            $netSalary = $grossSalary - $totalDeductions;
            
            // Create salary deduction record
            $salaryDeduction = self::create([
                'period_month' => $month,
                'period_year' => $year,
                'user_id' => $member->id,
                'gross_salary' => $grossSalary,
                'loan_deduction' => $totalLoanDeduction,
                'savings_deduction' => $savingsDeduction,
                'other_deductions' => $otherDeductions,
                'total_deductions' => $totalDeductions,
                'net_salary' => $netSalary,
                'deduction_date' => now(),
                'processed_by' => $processedBy,
                'status' => 'processed',
                'notes' => $options['notes'] ?? null,
            ]);
            
            // TODO: Create journal entry
            // $journal = $salaryDeduction->createJournalEntry();
            // $salaryDeduction->update(['journal_id' => $journal->id]);
            
            DB::commit();
            
            return $salaryDeduction;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get deduction breakdown.
     */
    public function getBreakdown(): array
    {
        return [
            'period' => $this->period_display,
            'member' => [
                'name' => $this->user->full_name,
                'employee_id' => $this->user->employee_id,
            ],
            'gross_salary' => $this->gross_salary,
            'deductions' => [
                'loan' => $this->loan_deduction,
                'savings' => $this->savings_deduction,
                'other' => $this->other_deductions,
                'total' => $this->total_deductions,
            ],
            'net_salary' => $this->net_salary,
            'deduction_percentage' => $this->gross_salary > 0 
                ? round(($this->total_deductions / $this->gross_salary) * 100, 2)
                : 0,
        ];
    }

    /**
     * Get total deductions for period.
     */
    public static function getTotalForPeriod(int $month, int $year): array
    {
        $deductions = self::byPeriod($month, $year)->get();
        
        return [
            'member_count' => $deductions->count(),
            'total_gross' => $deductions->sum('gross_salary'),
            'total_loan_deduction' => $deductions->sum('loan_deduction'),
            'total_savings_deduction' => $deductions->sum('savings_deduction'),
            'total_other_deduction' => $deductions->sum('other_deductions'),
            'total_deductions' => $deductions->sum('total_deductions'),
            'total_net_salary' => $deductions->sum('net_salary'),
        ];
    }

    /**
     * Get member's annual summary.
     */
    public static function getMemberAnnualSummary(int $userId, int $year): array
    {
        $deductions = self::where('user_id', $userId)
            ->where('period_year', $year)
            ->orderBy('period_month')
            ->get();
        
        return [
            'year' => $year,
            'months_processed' => $deductions->count(),
            'total_gross_salary' => $deductions->sum('gross_salary'),
            'total_loan_deduction' => $deductions->sum('loan_deduction'),
            'total_savings_deduction' => $deductions->sum('savings_deduction'),
            'total_other_deductions' => $deductions->sum('other_deductions'),
            'total_deductions' => $deductions->sum('total_deductions'),
            'total_net_salary' => $deductions->sum('net_salary'),
            'average_gross_salary' => $deductions->avg('gross_salary'),
            'average_deduction' => $deductions->avg('total_deductions'),
            'monthly_details' => $deductions->map(function($d) {
                return [
                    'month' => $d->period_short,
                    'gross' => $d->gross_salary,
                    'deductions' => $d->total_deductions,
                    'net' => $d->net_salary,
                ];
            }),
        ];
    }
}