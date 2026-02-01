<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Saving extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'savings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'cash_account_id',
        'saving_type_id',      // NEW: Foreign key to saving_types
        'savings_type',        // DEPRECATED: Keep for backward compatibility
        'amount',
        'interest_percentage',
        'final_amount',
        'transaction_date',
        'status',
        'notes',
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
            'amount' => 'decimal:2',
            'interest_percentage' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    /**
     * Get the user who owns the saving.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the cash account.
     */
    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    /**
     * Get the approver.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the saving type (NEW).
     */
    public function savingType()
    {
        return $this->belongsTo(SavingType::class, 'saving_type_id');
    }

    /**
     * Scope query by savings type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('savings_type', $type);
    }

    /**
     * Scope query by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope query for approved savings.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope query for pending savings.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope query for rejected savings.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
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
     * Scope query for date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    /**
     * Get savings type display name.
     */
    public function getTypeNameAttribute(): string
    {
        return match($this->savings_type) {
            'principal' => 'Simpanan Pokok',
            'mandatory' => 'Simpanan Wajib',
            'voluntary' => 'Simpanan Sukarela',
            'holiday' => 'Simpanan Hari Raya',
            default => $this->savings_type,
        };
    }

    /**
     * Get status display name.
     */
    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Menunggu Persetujuan',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
            default => $this->status,
        };
    }

    /**
     * Get status color for UI.
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Check if saving is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if saving is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if saving is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Calculate interest amount.
     */
    public function getInterestAmountAttribute(): float
    {
        return $this->final_amount - $this->amount;
    }

    /**
     * Calculate final amount with interest.
     */
    public static function calculateFinalAmount(float $amount, float $interestPercentage): float
    {
        $interest = $amount * ($interestPercentage / 100);
        return $amount + $interest;
    }

    /**
     * Get total savings by type for a user.
     */
    public static function getTotalByType(int $userId, string $type): float
    {
        return self::where('user_id', $userId)
                   ->where('savings_type', $type)
                   ->where('status', 'approved')
                   ->sum('final_amount');
    }

    /**
     * Get total all savings for a user.
     */
    public static function getTotalForUser(int $userId): float
    {
        return self::where('user_id', $userId)
                   ->where('status', 'approved')
                   ->sum('final_amount');
    }

    /**
     * Check if user has principal savings.
     */
    public static function hasPrincipal(int $userId): bool
    {
        return self::where('user_id', $userId)
                   ->where('savings_type', 'principal')
                   ->where('status', 'approved')
                   ->exists();
    }
}