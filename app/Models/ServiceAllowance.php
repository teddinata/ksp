<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\AutoJournalService;

class ServiceAllowance extends Model
{
    use HasFactory;

    protected $table = 'service_allowances';

    /**
     * ✅ UPDATED: New fillable attributes
     */
    protected $fillable = [
        'user_id',
        'period_month',
        'period_year',
        'received_amount', // ✅ NEW: Dari RS
        'installment_paid', // ✅ NEW: Dipotong untuk cicilan
        'remaining_amount', // ✅ NEW: Sisa untuk member
        'status',
        'payment_date',
        'notes',
        'distributed_by',
    ];

    /**
     * ✅ UPDATED: New casts
     */
    protected function casts(): array
    {
        return [
            'period_month' => 'integer',
            'period_year' => 'integer',
            'received_amount' => 'decimal:2', // ✅ NEW
            'installment_paid' => 'decimal:2', // ✅ NEW
            'remaining_amount' => 'decimal:2', // ✅ NEW
            'payment_date' => 'date',
        ];
    }

    // ==================== RELATIONSHIPS ====================

    public function user()
    {
        return $this->belongsTo(User::class , 'user_id');
    }

    public function distributedBy()
    {
        return $this->belongsTo(User::class , 'distributed_by');
    }

    // ==================== SCOPES ====================

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

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

    /**
     * ✅ UPDATED: Status display names
     */
    public function getStatusNameAttribute(): string
    {
        return match ($this->status) {
                'pending' => 'Menunggu Input',
                'processed' => 'Sudah Diproses',
                'paid' => 'Sudah Dibayar',
                'cancelled' => 'Dibatalkan',
                default => $this->status,
            };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
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

    // ==================== STATUS CHECKERS ====================

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    // ==================== METHODS ====================

    /**
     * Mark as paid (if not auto-processed)
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
     * ✅ NEW: Process service allowance with auto-installment payment
     * 
     * Business Logic:
     * 1. Terima nominal dari RS (manual input)
     * 2. Cek cicilan bulan ini
     * 3. Auto-potong cicilan
     * 4. Hitung sisa
     * 
     * @param User $member
     * @param int $month
     * @param int $year
     * @param float $receivedAmount
     * @param int $processedBy
     * @param string|null $notes
     * @return array
     */
    public static function processForMember(
        User $member,
        int $month,
        int $year,
        float $receivedAmount,
        int $processedBy,
        ?string $notes = null
        ): array
    {
        DB::beginTransaction();

        try {
            // Check if already exists
            $existing = self::where('user_id', $member->id)
                ->byPeriod($month, $year)
                ->first();

            if ($existing) {
                throw new \Exception('Jasa pelayanan untuk member ini di periode ini sudah ada');
            }

            // Get installments for this period
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth();

            $currentMonthInstallments = Installment::whereHas('loan', function ($q) use ($member) {
                $q->where('user_id', $member->id)
                    ->whereIn('status', ['disbursed', 'active']);
            })
                ->where('status', 'pending')
                ->whereBetween('due_date', [$startDate, $endDate])
                ->orderBy('due_date')
                ->get();

            $totalInstallmentDue = $currentMonthInstallments->sum('total_amount');

            // Calculate payment distribution
            $paidAmount = 0;
            $remainingAllowance = $receivedAmount;
            $remainingInstallment = 0;

            if ($totalInstallmentDue > 0) {
                if ($receivedAmount >= $totalInstallmentDue) {
                    // Jasa pelayanan CUKUP
                    $paidAmount = $totalInstallmentDue;
                    $remainingAllowance = $receivedAmount - $totalInstallmentDue;

                    // Auto pay all installments
                    foreach ($currentMonthInstallments as $installment) {
                        $installment->update([
                            'status' => 'auto_paid',
                            'payment_date' => now(),
                            'payment_method' => 'service_allowance',
                            'notes' => 'Dibayar otomatis dari jasa pelayanan periode ' .
                            Carbon::create($year, $month, 1)->format('F Y'),
                        ]);

                        // Update loan remaining principal
                        $installment->loan->remaining_principal -= $installment->principal_amount;
                        $installment->loan->save();

                        // Check if loan is paid off
                        if ($installment->loan->remaining_principal <= 0) {
                            $installment->loan->status = 'paid_off';
                            $installment->loan->save();
                        }
                    }

                }
                else {
                    // Jasa pelayanan TIDAK CUKUP
                    $paidAmount = $receivedAmount;
                    $remainingInstallment = $totalInstallmentDue - $receivedAmount;
                    $remainingAllowance = 0;

                    // Pay partial installments
                    $remainingToPay = $receivedAmount;

                    foreach ($currentMonthInstallments as $installment) {
                        if ($remainingToPay <= 0)
                            break;

                        if ($remainingToPay >= $installment->total_amount) {
                            // Pay full
                            $installment->update([
                                'status' => 'auto_paid',
                                'payment_date' => now(),
                                'payment_method' => 'service_allowance',
                                'notes' => 'Dibayar otomatis dari jasa pelayanan',
                            ]);

                            $remainingToPay -= $installment->total_amount;

                            // Update loan
                            $installment->loan->remaining_principal -= $installment->principal_amount;
                            $installment->loan->save();

                        }
                        else {
                            // Partial payment (sisanya member bayar manual)
                            $installment->update([
                                'paid_amount' => $remainingToPay,
                                'status' => 'partial',
                                'payment_date' => now(),
                                'payment_method' => 'service_allowance',
                                'notes' => "Dibayar sebagian Rp " . number_format($remainingToPay, 0, ',', '.') .
                                " dari jasa pelayanan. Sisa: Rp " .
                                number_format($installment->total_amount - $remainingToPay, 0, ',', '.'),
                            ]);

                            $remainingToPay = 0;
                        }
                    }
                }
            }
            else {
                // No installments, all for member
                $remainingAllowance = $receivedAmount;
            }

            // Create service allowance record
            $serviceAllowance = self::create([
                'user_id' => $member->id,
                'period_month' => $month,
                'period_year' => $year,
                'received_amount' => $receivedAmount,
                'installment_paid' => $paidAmount,
                'remaining_amount' => $remainingAllowance,
                'status' => 'processed',
                'payment_date' => now(),
                'distributed_by' => $processedBy,
                'notes' => $notes,
            ]);

            // ✅ NEW: Create auto-journal if installments were paid
            if ($paidAmount > 0) {
                // Hitung porsi principal dan interest secara proporsional
                // Full-paid installments: pakai full amount
                // Partial-paid: pakai rasio
                $totalPrincipal = 0;
                $totalInterest = 0;

                foreach ($currentMonthInstallments as $inst) {
                    if ($inst->status === 'auto_paid') {
                        // Full payment
                        $totalPrincipal += $inst->principal_amount;
                        $totalInterest += $inst->interest_amount;
                    }
                    elseif ($inst->status === 'partial' && $inst->paid_amount > 0) {
                        // Partial payment — hitung proporsional
                        $ratio = $inst->paid_amount / $inst->total_amount;
                        $totalPrincipal += $inst->principal_amount * $ratio;
                        $totalInterest += $inst->interest_amount * $ratio;
                    }
                }

                // Koreksi pembulatan agar principal + interest = paidAmount
                $calculatedTotal = round($totalPrincipal + $totalInterest, 2);
                if ($calculatedTotal > 0 && abs($calculatedTotal - $paidAmount) > 0.01) {
                    $totalPrincipal = $paidAmount - $totalInterest;
                }

                AutoJournalService::serviceAllowanceProcessed(
                    $serviceAllowance,
                    $processedBy,
                    round($totalPrincipal, 2),
                    round($totalInterest, 2)
                );
            }

            DB::commit();

            return [
                'service_allowance' => $serviceAllowance,
                'summary' => [
                    'received_from_hospital' => $receivedAmount,
                    'used_for_installments' => $paidAmount,
                    'returned_to_member' => $remainingAllowance,
                    'remaining_installment_due' => $remainingInstallment,
                    'installments_paid_count' => $currentMonthInstallments->where('status', 'auto_paid')->count(),
                    'message' => self::generateSummaryMessage($remainingAllowance, $remainingInstallment),
                ],
            ];

        }
        catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Generate summary message
     */
    private static function generateSummaryMessage(float $remaining, float $installmentDue): string
    {
        if ($installmentDue > 0) {
            return "Member harus bayar sisa cicilan: Rp " . number_format($installmentDue, 0, ',', '.');
        }
        elseif ($remaining > 0) {
            return "Sisa jasa pelayanan untuk member: Rp " . number_format($remaining, 0, ',', '.');
        }
        else {
            return "Jasa pelayanan pas untuk bayar cicilan.";
        }
    }

    // ==================== STATIC QUERIES ====================

    /**
     * Get total distributed for a period
     */
    public static function getTotalForPeriod(int $month, int $year): float
    {
        return self::where('period_month', $month)
            ->where('period_year', $year)
            ->sum('received_amount');
    }

    /**
     * Get total paid for installments in a period
     */
    public static function getTotalPaidForInstallmentsInPeriod(int $month, int $year): float
    {
        return self::where('period_month', $month)
            ->where('period_year', $year)
            ->sum('installment_paid');
    }

    /**
     * Get member's total service allowance for a year
     */
    public static function getMemberTotalForYear(int $userId, int $year): float
    {
        return self::where('user_id', $userId)
            ->where('period_year', $year)
            ->whereIn('status', ['processed', 'paid'])
            ->sum('received_amount');
    }

    /**
     * Get member's total remaining (yang diterima member) for a year
     */
    public static function getMemberTotalRemainingForYear(int $userId, int $year): float
    {
        return self::where('user_id', $userId)
            ->where('period_year', $year)
            ->whereIn('status', ['processed', 'paid'])
            ->sum('remaining_amount');
    }
}