<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Installment extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'installments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'loan_id',
        'installment_number',
        'due_date',
        'payment_date',
        'principal_amount',
        'interest_amount',
        'total_amount',
        'paid_amount',
        'remaining_principal',
        'status',
        'payment_method',
        'notes',
        'confirmed_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'payment_date' => 'date',
            'principal_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remaining_principal' => 'decimal:2',
        ];
    }

    /**
     * Get the loan.
     */
    public function loan()
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    /**
     * Get the confirmer.
     */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Scope query by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope query for pending installments.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope query for paid installments.
     */
    public function scopePaid($query)
    {
        return $query->whereIn('status', ['auto_paid', 'paid']);
    }

    /**
     * Scope query for overdue installments.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    /**
     * Scope query by loan.
     */
    public function scopeByLoan($query, int $loanId)
    {
        return $query->where('loan_id', $loanId);
    }

    /**
     * Get status display name.
     */
    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Belum Dibayar',
            'auto_paid' => 'Dibayar Otomatis',
            'manual_pending' => 'Pembayaran Manual (Menunggu Konfirmasi)',
            'paid' => 'Sudah Dibayar',
            'overdue' => 'Terlambat',
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
            'auto_paid' => 'success',
            'manual_pending' => 'info',
            'paid' => 'success',
            'overdue' => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Check if installment is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if installment is paid.
     */
    public function isPaid(): bool
    {
        return in_array($this->status, ['auto_paid', 'paid']);
    }

    /**
     * Check if installment is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->status === 'overdue';
    }

    /**
     * Get days overdue.
     */
    public function getDaysOverdueAttribute(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }

        return now()->diffInDays($this->due_date);
    }

    /**
     * Get days until due.
     */
    public function getDaysUntilDueAttribute(): int
    {
        if ($this->isPaid() || $this->isOverdue()) {
            return 0;
        }

        return $this->due_date->diffInDays(now());
    }

    /**
     * Mark as paid.
     */
    public function markAsPaid(string $method, ?int $confirmedBy = null, ?string $notes = null): void
    {
        $this->update([
            'status' => $method === 'service_allowance' ? 'auto_paid' : 'paid',
            'payment_date' => now(),
            'paid_amount' => $this->total_amount,
            'payment_method' => $method,
            'notes' => $notes,
            'confirmed_by' => $confirmedBy,
        ]);

        // Check if loan is fully paid
        $this->loan->checkIfPaidOff();
    }

    /**
     * Check and update overdue status.
     */
    public static function checkOverdueInstallments(): void
    {
        self::where('status', 'pending')
            ->where('due_date', '<', now())
            ->update(['status' => 'overdue']);
    }
}