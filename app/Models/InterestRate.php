<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InterestRate extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'interest_rates';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'cash_account_id',
        'transaction_type',
        'rate_percentage',
        'effective_date',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_percentage' => 'decimal:2',
            'effective_date' => 'date',
        ];
    }

    /**
     * Get the cash account.
     */
    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    /**
     * Get the user who updated this rate.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope query by transaction type.
     */
    public function scopeForSavings($query)
    {
        return $query->where('transaction_type', 'savings');
    }

    /**
     * Scope query by transaction type.
     */
    public function scopeForLoans($query)
    {
        return $query->where('transaction_type', 'loans');
    }

    /**
     * Scope query for effective rates.
     */
    public function scopeEffective($query, $date = null)
    {
        $date = $date ?? now();
        return $query->where('effective_date', '<=', $date)
                    ->orderBy('effective_date', 'desc');
    }

    /**
     * Get transaction type display name.
     */
    public function getTransactionTypeNameAttribute(): string
    {
        return match($this->transaction_type) {
            'savings' => 'Simpanan',
            'loans' => 'Pinjaman',
            default => $this->transaction_type,
        };
    }
}