<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chart_of_accounts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'category',
        'account_type',
        'is_debit',
        'is_contra',           // NEW: Flag for contra accounts
        'contra_description',  // NEW: Description for contra usage
        'is_active',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_debit' => 'boolean',
            'is_contra' => 'boolean',  // NEW
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope query for active accounts only
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query by category
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $category
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope query by account type
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('account_type', $type);
    }

    /**
     * Get accounts with debit normal balance
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDebitAccounts($query)
    {
        return $query->where('is_debit', true);
    }

    /**
     * Get accounts with credit normal balance
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreditAccounts($query)
    {
        return $query->where('is_debit', false);
    }

    /**
     * Search accounts by code or name
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $search
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('code', 'like', "%{$search}%")
              ->orWhere('name', 'like', "%{$search}%");
        });
    }

    /**
     * Get category display name
     *
     * @return string
     */
    public function getCategoryNameAttribute(): string
    {
        return match($this->category) {
            'assets' => 'Aset',
            'liabilities' => 'Kewajiban',
            'equity' => 'Modal',
            'revenue' => 'Pendapatan',
            'expenses' => 'Beban',
            default => $this->category,
        };
    }

    /**
     * Get balance type (Debit/Credit)
     *
     * @return string
     */
    public function getBalanceTypeAttribute(): string
    {
        return $this->is_debit ? 'Debit' : 'Kredit';
    }

    // ==================== NEW: CONTRA ACCOUNT METHODS ====================

    /**
     * Scope for contra accounts only.
     */
    public function scopeContraAccounts($query)
    {
        return $query->where('is_contra', true);
    }

    /**
     * Scope for normal (non-contra) accounts.
     */
    public function scopeNormalAccounts($query)
    {
        return $query->where('is_contra', false);
    }

    /**
     * Check if this is a contra account.
     */
    public function isContra(): bool
    {
        return $this->is_contra === true;
    }

    /**
     * Get effective balance type considering contra nature.
     * 
     * Contra accounts have opposite balance compared to their category:
     * - Assets contra: Credit balance (instead of Debit)
     * - Liabilities contra: Debit balance (instead of Credit)
     * - etc.
     */
    public function getEffectiveBalanceTypeAttribute(): string
    {
        if ($this->is_contra) {
            // Reverse the normal balance
            return $this->is_debit ? 'Kredit' : 'Debit';
        }
        
        return $this->balance_type;
    }

    /**
     * Get account characteristics summary.
     */
    public function getCharacteristicsAttribute(): string
    {
        $chars = [];
        
        $chars[] = $this->category_name;
        $chars[] = $this->balance_type;
        
        if ($this->is_contra) {
            $chars[] = 'Kontra';
        }
        
        if (!$this->is_active) {
            $chars[] = 'Non-aktif';
        }
        
        return implode(' • ', $chars);
    }
}