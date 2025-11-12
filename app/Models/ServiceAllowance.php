<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ServiceAllowance extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'service_allowances';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'period_month',
        'period_year',
        'base_amount',
        'savings_bonus',
        'loan_bonus',
        'total_amount',
        'status',
        'payment_date',
        'notes',
        'distributed_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_month' => 'integer',
            'period_year' => 'integer',
            'base_amount' => 'decimal:2',
            'savings_bonus' => 'decimal:2',
            'loan_bonus' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    /**
     * Get the user who owns the service allowance.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the distributor.
     */
    public function distributedBy()
    {
        return $this->belongsTo(User::class, 'distributed_by');
    }

    /**
     * Scope query by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope query for pending allowances.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope query for paid allowances.
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    /**
     * Scope query by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope query by period.
     */
    public function scopeByPeriod($query, int $month, int $year)
    {
        return $query->where('period_month', $month)
                     ->where('period_year', $year);
    }

    /**
     * Scope query by year.
     */
    public function scopeByYear($query, int $year)
    {
        return $query->where('period_year', $year);
    }

    /**
     * Get status display name.
     */
    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Menunggu Pembayaran',
            'paid' => 'Sudah Dibayar',
            'cancelled' => 'Dibatalkan',
            default => $this->status,
        };
    }

    /**
     * Get status color.
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'paid' => 'success',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Get period display.
     */
    public function getPeriodDisplayAttribute(): string
    {
        $monthName = Carbon::create($this->period_year, $this->period_month, 1)
            ->format('F Y');
        
        return $monthName;
    }

    /**
     * Get period short display.
     */
    public function getPeriodShortAttribute(): string
    {
        return str_pad($this->period_month, 2, '0', STR_PAD_LEFT) . '/' . $this->period_year;
    }

    /**
     * Check if allowance is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if allowance is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Mark as paid.
     */
    public function markAsPaid(int $distributedBy, ?string $notes = null): void
    {
        $this->update([
            'status' => 'paid',
            'payment_date' => now(),
            'distributed_by' => $distributedBy,
            'notes' => $notes ?? $this->notes,
        ]);
    }

    /**
     * Calculate service allowance for a member.
     * 
     * Business Logic:
     * - Base amount: Fixed amount per member (e.g., Rp 50,000)
     * - Savings bonus: Percentage of total savings (e.g., 1% of savings)
     * - Loan bonus: Percentage of loan interest paid (e.g., 10% of interest)
     *
     * @param User $user
     * @param int $month
     * @param int $year
     * @param float $baseAmount Default base amount
     * @param float $savingsRate Percentage of savings
     * @param float $loanRate Percentage of loan interest
     * @return array
     */
    public static function calculateForMember(
        User $user,
        int $month,
        int $year,
        float $baseAmount = 50000,
        float $savingsRate = 1.0,
        float $loanRate = 10.0
    ): array {
        // Base amount - fixed for all members
        $base = $baseAmount;

        // Savings bonus - percentage of total savings
        $totalSavings = $user->total_savings;
        $savingsBonus = $totalSavings * ($savingsRate / 100);

        // Loan bonus - percentage of interest paid in the period
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = Carbon::create($year, $month, 1)->endOfMonth();

        $loanInterestPaid = Installment::whereHas('loan', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->whereIn('status', ['auto_paid', 'paid'])
        ->whereBetween('payment_date', [$startDate, $endDate])
        ->sum('interest_amount');

        $loanBonus = $loanInterestPaid * ($loanRate / 100);

        // Total
        $total = $base + $savingsBonus + $loanBonus;

        return [
            'base_amount' => round($base, 2),
            'savings_bonus' => round($savingsBonus, 2),
            'loan_bonus' => round($loanBonus, 2),
            'total_amount' => round($total, 2),
            'calculation_details' => [
                'total_savings' => $totalSavings,
                'savings_rate' => $savingsRate . '%',
                'loan_interest_paid' => $loanInterestPaid,
                'loan_rate' => $loanRate . '%',
            ],
        ];
    }

    /**
     * Distribute service allowances to all active members.
     *
     * @param int $month
     * @param int $year
     * @param int $distributedBy
     * @param array $options
     * @return array
     */
    public static function distributeToMembers(
        int $month,
        int $year,
        int $distributedBy,
        array $options = []
    ): array {
        $baseAmount = $options['base_amount'] ?? 50000;
        $savingsRate = $options['savings_rate'] ?? 1.0;
        $loanRate = $options['loan_rate'] ?? 10.0;

        // Check if already distributed
        $existing = self::where('period_month', $month)
            ->where('period_year', $year)
            ->count();

        if ($existing > 0) {
            throw new \Exception('Service allowance for this period has already been distributed');
        }

        // Get all active members
        $members = User::members()->active()->get();
        $distributed = [];
        $totalAmount = 0;

        foreach ($members as $member) {
            $calculation = self::calculateForMember(
                $member,
                $month,
                $year,
                $baseAmount,
                $savingsRate,
                $loanRate
            );

            $allowance = self::create([
                'user_id' => $member->id,
                'period_month' => $month,
                'period_year' => $year,
                'base_amount' => $calculation['base_amount'],
                'savings_bonus' => $calculation['savings_bonus'],
                'loan_bonus' => $calculation['loan_bonus'],
                'total_amount' => $calculation['total_amount'],
                'status' => 'pending',
                'distributed_by' => $distributedBy,
            ]);

            $distributed[] = $allowance;
            $totalAmount += $calculation['total_amount'];
        }

        return [
            'period' => Carbon::create($year, $month, 1)->format('F Y'),
            'members_count' => count($distributed),
            'total_amount' => $totalAmount,
            'allowances' => $distributed,
        ];
    }

    /**
     * Get total distributed for a period.
     */
    public static function getTotalForPeriod(int $month, int $year): float
    {
        return self::where('period_month', $month)
            ->where('period_year', $year)
            ->sum('total_amount');
    }

    /**
     * Get total paid for a period.
     */
    public static function getTotalPaidForPeriod(int $month, int $year): float
    {
        return self::where('period_month', $month)
            ->where('period_year', $year)
            ->where('status', 'paid')
            ->sum('total_amount');
    }

    /**
     * Get member's total service allowance for a year.
     */
    public static function getMemberTotalForYear(int $userId, int $year): float
    {
        return self::where('user_id', $userId)
            ->where('period_year', $year)
            ->where('status', 'paid')
            ->sum('total_amount');
    }
}