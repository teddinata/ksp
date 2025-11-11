<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashAccount extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cash_accounts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'type',
        'opening_balance',
        'current_balance',
        'description',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the managers assigned to this cash account.
     */
    public function managers()
    {
        return $this->belongsToMany(User::class, 'cash_account_managers', 'cash_account_id', 'manager_id')
                    ->withPivot('assigned_at', 'is_active')
                    ->withTimestamps();
    }

    /**
     * Get active managers only.
     */
    public function activeManagers()
    {
        return $this->managers()->wherePivot('is_active', true);
    }

    /**
     * Get interest rates for this cash account.
     */
    public function interestRates()
    {
        return $this->hasMany(InterestRate::class, 'cash_account_id');
    }

    /**
     * Get current interest rate for savings.
     */
    public function currentSavingsRate()
    {
        return $this->interestRates()
                    ->where('transaction_type', 'savings')
                    ->where('effective_date', '<=', now())
                    ->orderBy('effective_date', 'desc')
                    ->first();
    }

    /**
     * Get current interest rate for loans.
     */
    public function currentLoanRate()
    {
        return $this->interestRates()
                    ->where('transaction_type', 'loans')
                    ->where('effective_date', '<=', now())
                    ->orderBy('effective_date', 'desc')
                    ->first();
    }

    /**
     * Scope query for active cash accounts only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query by type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope query for cash accounts managed by specific user.
     */
    public function scopeManagedBy($query, int $userId)
    {
        return $query->whereHas('managers', function($q) use ($userId) {
            $q->where('users.id', $userId)
              ->where('cash_account_managers.is_active', true);
        });
    }

    /**
     * Check if user is manager of this cash account.
     */
    public function isManagedBy(int $userId): bool
    {
        return $this->activeManagers()
                    ->where('users.id', $userId)
                    ->exists();
    }

    /**
     * Get type display name.
     */
    public function getTypeNameAttribute(): string
    {
        return match($this->type) {
            'I' => 'Kas Umum (General)',
            'II' => 'Kas Sosial (Social)',
            'III' => 'Kas Pengadaan (Procurement)',
            'IV' => 'Kas Hadiah (Gifts)',
            'V' => 'Bank',
            default => $this->type,
        };
    }

    /**
     * Update current balance.
     */
    public function updateBalance(float $amount, string $type = 'add'): void
    {
        if ($type === 'add') {
            $this->current_balance += $amount;
        } else {
            $this->current_balance -= $amount;
        }
        $this->save();
    }
}