<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use App\Services\AutoJournalService;

class Loan extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'loans';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'cash_account_id',
        'loan_number',
        'principal_amount',
        'remaining_principal',
        'interest_percentage',
        'tenure_months',
        'installment_amount',
        'status',
        'deduction_method', // NEW: none/salary/service_allowance/mixed
        'salary_deduction_percentage', // NEW: Percentage for salary deduction
        'service_allowance_deduction_percentage', // NEW: Percentage for service allowance deduction
        'is_early_settlement', // NEW: Flag for early settlement
        'settlement_date', // NEW: Date of settlement
        'settlement_amount', // NEW: Amount paid for settlement
        'settled_by', // NEW: Admin who processed settlement
        'settlement_notes', // NEW: Notes about settlement
        'application_date',
        'approval_date',
        'disbursement_date',
        'loan_purpose',
        'document_path',
        'rejection_reason',
        'approved_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'remaining_principal' => 'decimal:2',
            'interest_percentage' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'salary_deduction_percentage' => 'decimal:2', // NEW
            'service_allowance_deduction_percentage' => 'decimal:2', // NEW
            'settlement_amount' => 'decimal:2', // NEW
            'is_early_settlement' => 'boolean', // NEW
            'application_date' => 'date',
            'approval_date' => 'date',
            'disbursement_date' => 'date',
            'settlement_date' => 'date', // NEW
        ];
    }

    /**
     * Get the user who owns the loan.
     */
    public function user()
    {
        return $this->belongsTo(User::class , 'user_id');
    }

    /**
     * Get the cash account.
     */
    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class , 'cash_account_id');
    }

    /**
     * Get the approver.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class , 'approved_by');
    }

    /**
     * Get the installments for this loan.
     */
    public function installments()
    {
        return $this->hasMany(Installment::class , 'loan_id');
    }

    /**
     * Scope query by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope query for pending loans.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope query for approved loans.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope query for active loans.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['disbursed', 'active']);
    }

    /**
     * Scope query for completed loans.
     */
    public function scopePaidOff($query)
    {
        return $query->where('status', 'paid_off');
    }

    /**
     * Scope query by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope query by cash account.
     */
    public function scopeByCashAccount($query, int $cashAccountId)
    {
        return $query->where('cash_account_id', $cashAccountId);
    }

    /**
     * Get status display name.
     */
    public function getStatusNameAttribute(): string
    {
        return match ($this->status) {
                'pending' => 'Menunggu Persetujuan',
                'approved' => 'Disetujui',
                'rejected' => 'Ditolak',
                'disbursed' => 'Sudah Dicairkan',
                'active' => 'Aktif (Cicilan Berjalan)',
                'paid_off' => 'Lunas',
                default => $this->status,
            };
    }

    /**
     * Get status color.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
                'pending' => 'warning',
                'approved' => 'info',
                'rejected' => 'danger',
                'disbursed' => 'primary',
                'active' => 'success',
                'paid_off' => 'secondary',
                default => 'secondary',
            };
    }

    /**
     * Check if loan is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if loan is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if loan is active.
     */
    public function isActive(): bool
    {
        return in_array($this->status, ['disbursed', 'active']);
    }

    /**
     * Check if loan is paid off.
     */
    public function isPaidOff(): bool
    {
        return $this->status === 'paid_off';
    }

    /**
     * Get total amount (principal + all interest).
     */
    public function getTotalAmountAttribute(): float
    {
        return $this->installment_amount * $this->tenure_months;
    }

    /**
     * Get total interest.
     */
    public function getTotalInterestAttribute(): float
    {
        return $this->total_amount - $this->principal_amount;
    }

    /**
     * Get remaining principal.
     */
    public function getRemainingPrincipalAttribute(): float
    {
        return $this->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->sum('principal_amount');
    }

    /**
     * Get paid installments count.
     */
    public function getPaidInstallmentsCountAttribute(): int
    {
        return $this->installments()
            ->whereIn('status', ['auto_paid', 'paid'])
            ->count();
    }

    /**
     * Get pending installments count.
     */
    public function getPendingInstallmentsCountAttribute(): int
    {
        return $this->installments()
            ->where('status', 'pending')
            ->count();
    }

    /**
     * Get overdue installments count.
     */
    public function getOverdueInstallmentsCountAttribute(): int
    {
        return $this->installments()
            ->where('status', 'overdue')
            ->count();
    }

    /**
     * Generate unique loan number.
     */
    public static function generateLoanNumber(): string
    {
        $date = now()->format('Ymd');
        $count = self::whereDate('created_at', now())->count() + 1;
        return 'LN-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate monthly installment using reducing balance method.
     * 
     * Formula: P * [i(1+i)^n] / [(1+i)^n - 1]
     * Where:
     * P = Principal
     * i = Monthly interest rate (annual rate / 12 / 100)
     * n = Number of months
     */
    public static function calculateInstallment(float $principal, float $annualRate, int $months): float
    {
        if ($annualRate == 0) {
            return $principal / $months;
        }

        $monthlyRate = $annualRate / 12 / 100;
        $power = pow(1 + $monthlyRate, $months);

        $installment = $principal * ($monthlyRate * $power) / ($power - 1);

        return round($installment, 0); // Round to nearest rupiah
    }

    /**
     * Create installment schedule.
     */
    public function createInstallmentSchedule(): void
    {
        $monthlyRate = $this->interest_percentage / 12 / 100;
        $remainingPrincipal = $this->principal_amount;

        for ($i = 1; $i <= $this->tenure_months; $i++) {
            $interestAmount = $remainingPrincipal * $monthlyRate;
            $principalAmount = $this->installment_amount - $interestAmount;
            $remainingPrincipal -= $principalAmount;

            // Adjust last installment to account for rounding
            if ($i == $this->tenure_months) {
                $principalAmount += $remainingPrincipal;
                $remainingPrincipal = 0;
            }

            Installment::create([
                'loan_id' => $this->id,
                'installment_number' => $i,
                'due_date' => Carbon::parse($this->disbursement_date)->addMonths($i),
                'principal_amount' => round($principalAmount, 2),
                'interest_amount' => round($interestAmount, 2),
                'total_amount' => $this->installment_amount,
                'paid_amount' => 0,
                'remaining_principal' => round($remainingPrincipal, 2),
                'status' => 'pending',
            ]);
        }
    }

    /**
     * Check and update overdue installments.
     */
    public function updateOverdueStatus(): void
    {
        $this->installments()
            ->where('status', 'pending')
            ->where('due_date', '<', now())
            ->update(['status' => 'overdue']);
    }

    /**
     * Check if loan is fully paid.
     */
    public function checkIfPaidOff(): void
    {
        $allPaid = $this->installments()
            ->whereNotIn('status', ['auto_paid', 'paid'])
            ->count() === 0;

        if ($allPaid && $this->isActive()) {
            $this->update(['status' => 'paid_off']);
        }
    }

    // ==================== NEW: EARLY SETTLEMENT & DEDUCTION ====================

    /**
     * Process early settlement.
     * 
     * BUSINESS LOGIC:
     * - Hanya bayar remaining_principal (tanpa bunga)
     * - Mark all pending installments as cancelled
     * - Set loan status to paid_off
     * 
     * @param int $settledBy
     * @param string|null $notes
     * @return array Settlement summary
     */
    /**
     * Process early settlement.
     * ✅ UPDATED: Tambah auto-journal
     */
    public function processEarlySettlement(int $settledBy, ?string $notes = null): array
    {
        if (!$this->isActive()) {
            throw new \Exception('Hanya pinjaman aktif yang dapat dilunasi');
        }

        if ($this->remaining_principal <= 0) {
            throw new \Exception('Pinjaman sudah lunas');
        }

        \DB::transaction(function () use ($settledBy, $notes) {
            // Settlement amount = remaining principal only (no interest)
            $settlementAmount = $this->remaining_principal;

            // Cancel all pending installments
            $this->installments()
                ->whereIn('status', ['pending', 'overdue'])
                ->update([
                'status' => 'cancelled',
                'notes' => 'Dibatalkan karena pelunasan dipercepat',
            ]);

            // Update loan
            $this->update([
                'status' => 'paid_off',
                'is_early_settlement' => true,
                'settlement_date' => now(),
                'settlement_amount' => $settlementAmount,
                'settled_by' => $settledBy,
                'settlement_notes' => $notes,
                'remaining_principal' => 0,
            ]);

            // ✅ NEW: Create auto-journal for early settlement
            AutoJournalService::loanEarlySettlement($this, $settledBy);

            // Log activity
            try {
                ActivityLog::createLog([
                    'activity' => 'early_settlement',
                    'module' => 'loans',
                    'description' => "Pelunasan dipercepat pinjaman {$this->loan_number} sebesar Rp " .
                    number_format($settlementAmount, 0, ',', '.'),
                    'user_id' => $settledBy, // Explicitly pass user_id
                ]);
            }
            catch (\Exception $e) {
            // Ignore log failures
            }
        });

        return [
            'loan_number' => $this->loan_number,
            'original_principal' => $this->principal_amount,
            'settlement_amount' => $this->settlement_amount,
            'saved_interest' => $this->getTotalInterestAttribute() -
            $this->installments()->whereIn('status', ['paid', 'auto_paid'])->sum('interest_amount'),
            'message' => 'Pelunasan berhasil. Anda hanya membayar sisa pokok tanpa bunga.',
        ];
    }

    /**
     * Get deduction method display name.
     */
    public function getDeductionMethodNameAttribute(): string
    {
        return match ($this->deduction_method) {
                'none' => 'Bayar Manual',
                'salary' => 'Potong Gaji',
                'service_allowance' => 'Potong Jasa Pelayanan',
                'mixed' => 'Kombinasi (Gaji + Jasa)',
                default => $this->deduction_method,
            };
    }

    /**
     * Check if loan uses salary deduction.
     */
    public function usesSalaryDeduction(): bool
    {
        return in_array($this->deduction_method, ['salary', 'mixed']);
    }

    /**
     * Check if loan uses service allowance deduction.
     */
    public function usesServiceAllowanceDeduction(): bool
    {
        return in_array($this->deduction_method, ['service_allowance', 'mixed']);
    }
}