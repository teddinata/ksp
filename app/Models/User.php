<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'full_name',
        'employee_id',
        'email',
        'password',
        'role',
        'status',  // TAMBAHKAN INI
        'is_active',
        'phone_number',
        'address',
        'work_unit',
        'position',
        'joined_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'joined_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Check if user is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Check if user has specific role
     *
     * @param string $role
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Check if user is manager
     *
     * @return bool
     */
    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    /**
     * Check if user is admin
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is member/anggota
     *
     * @return bool
     */
    public function isMember(): bool
    {
        return $this->role === 'anggota';
    }

    /**
     * Scope query for active users only
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query by role
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $role
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope query for inactive users.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope query for suspended users.
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    /**
     * Scope query for members only.
     */
    public function scopeMembers($query)
    {
        return $query->where('role', 'anggota');
    }

    /**
     * Get user's savings.
     */
    public function savings()
    {
        return $this->hasMany(Saving::class, 'user_id');
    }

    /**
     * Get user's loans.
     */
    public function loans()
    {
        return $this->hasMany(Loan::class, 'user_id');
    }

    /**
     * Get total savings balance.
     */
    public function getTotalSavingsAttribute(): float
    {
        return $this->savings()
            ->where('status', 'approved')
            ->sum('final_amount');
    }

    /**
     * Get savings by type.
     */
    public function getSavingsByType(string $type): float
    {
        return $this->savings()
            ->where('savings_type', $type)
            ->where('status', 'approved')
            ->sum('final_amount');
    }

    /**
     * Get active loans.
     */
    public function getActiveLoansAttribute()
    {
        return $this->loans()
            ->whereIn('status', ['disbursed', 'active'])
            ->get();
    }

    /**
     * Get total loan balance.
     */
    public function getTotalLoanBalanceAttribute(): float
    {
        return $this->active_loans->sum('remaining_principal');
    }

    /**
     * Get monthly installment obligation.
     */
    public function getMonthlyInstallmentAttribute(): float
    {
        return $this->active_loans->sum('installment_amount');
    }

    /**
     * Get financial summary.
     */
    public function getFinancialSummary(): array
    {
        return [
            'savings' => [
                'total' => $this->total_savings,
                'principal' => $this->getSavingsByType('principal'),
                'mandatory' => $this->getSavingsByType('mandatory'),
                'voluntary' => $this->getSavingsByType('voluntary'),
                'holiday' => $this->getSavingsByType('holiday'),
            ],
            'loans' => [
                'active_count' => $this->active_loans->count(),
                'total_borrowed' => $this->active_loans->sum('principal_amount'),
                'remaining_balance' => $this->total_loan_balance,
                'monthly_installment' => $this->monthly_installment,
            ],
            'net_position' => $this->total_savings - $this->total_loan_balance,
        ];
    }

    /**
     * Check if member has overdue installments.
     */
    public function hasOverdueInstallments(): bool
    {
        return Installment::whereHas('loan', function($q) {
            $q->where('user_id', $this->id);
        })
        ->where('status', 'overdue')
        ->exists();
    }

    /**
     * Get membership duration in months.
     */
    public function getMembershipDurationAttribute(): int
    {
        if (!$this->joined_at) {  // PERBAIKI: joined_date -> joined_at
            return 0;
        }
        
        return $this->joined_at->diffInMonths(now());
    }

    /**
     * Get membership status display.
     * PERBAIKI: Pastikan selalu return string
     */
    public function getMembershipStatusAttribute(): string
    {
        if (!$this->isMember()) {
            return 'Not a member';
        }

        if ($this->hasOverdueInstallments()) {
            return 'Has overdue payments';
        }

        // PERBAIKI: Tambahkan fallback jika status null
        return match($this->status ?? 'active') {
            'active' => 'Active member',
            'inactive' => 'Inactive',
            'suspended' => 'Suspended',
            default => 'Active member',  // Default fallback
        };
    }

    /**
     * Format phone number for display.
     */
    public function getFormattedPhoneAttribute(): ?string
    {
        if (!$this->phone_number) {
            return null;
        }

        // Format: 0812-3456-7890
        $phone = preg_replace('/[^0-9]/', '', $this->phone_number);
        
        if (strlen($phone) >= 10) {
            return substr($phone, 0, 4) . '-' . 
                   substr($phone, 4, 4) . '-' . 
                   substr($phone, 8);
        }

        return $this->phone_number;
    }

    /**
     * Get initials for avatar.
     */
    public function getInitialsAttribute(): string
    {
        $words = explode(' ', $this->full_name);
        
        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
        }
        
        return strtoupper(substr($this->full_name, 0, 2));
    }

    /**
     * Get cash accounts managed by this user (Manager only).
     */
    public function managedCashAccounts()
    {
        return $this->belongsToMany(
            CashAccount::class,
            'cash_account_managers',  // Pivot table
            'manager_id',              // FK di pivot
            'cash_account_id'          // Related key
        )->withTimestamps();
    }

    /**
     * Check if user is managing specific cash account.
     */
    public function isManagingCashAccount(int $cashAccountId): bool
    {
        return $this->managedCashAccounts()
            ->where('cash_account_id', $cashAccountId)
            ->exists();
    }

    /**
     * Get count of managed accounts.
     */
    public function getManagedAccountsCountAttribute(): int
    {
        return $this->managedCashAccounts()->count();
    }
}