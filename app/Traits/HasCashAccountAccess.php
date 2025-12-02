<?php

namespace App\Traits;

use App\Models\CashAccount;
use App\Models\CashAccountManager;

/**
 * Trait: Cash Account Manager Helper (For Pivot Table Approach)
 * 
 * Add to User model to get cash account manager methods
 */
trait HasCashAccountAccess
{
    /**
     * Get cash accounts managed by this user (Many-to-Many)
     */
    public function managedCashAccounts()
    {
        return $this->belongsToMany(
            CashAccount::class, 
            'cash_account_managers', 
            'manager_id', 
            'cash_account_id'
        )
        ->withPivot('assigned_at', 'is_active')
        ->wherePivot('is_active', true)
        ->withTimestamps();
    }
    
    /**
     * Check if user is manager of specific cash account
     */
    public function isCashAccountManager(int $cashAccountId): bool
    {
        // Admin bisa akses semua
        if ($this->isAdmin()) {
            return true;
        }
        
        // Check if user is active manager of this cash account
        return CashAccountManager::where('manager_id', $this->id)
            ->where('cash_account_id', $cashAccountId)
            ->where('is_active', true)
            ->exists();
    }
    
    /**
     * Get cash accounts that user can access
     * 
     * Returns:
     * - Admin: All cash accounts
     * - Manager: Only cash accounts they manage
     * - Member: Empty collection
     */
    public function accessibleCashAccounts()
    {
        if ($this->isAdmin()) {
            // Admin can access all
            return CashAccount::active()->get();
        }
        
        if ($this->isManager()) {
            // Manager only their assigned cash accounts
            return $this->managedCashAccounts()->get();
        }
        
        // Members can't access cash accounts
        return collect([]);
    }
    
    /**
     * Check if user can perform transaction on cash account
     */
    public function canTransactOnCashAccount(int $cashAccountId): bool
    {
        return $this->isCashAccountManager($cashAccountId);
    }
    
    /**
     * Get cash account IDs that user can access
     */
    public function getAccessibleCashAccountIds(): array
    {
        return $this->accessibleCashAccounts()->pluck('id')->toArray();
    }
    
    /**
     * Assign this user as manager to cash account
     */
    public function assignToCashAccount(int $cashAccountId): CashAccountManager
    {
        // Check if already assigned
        $existing = CashAccountManager::where('manager_id', $this->id)
            ->where('cash_account_id', $cashAccountId)
            ->first();
        
        if ($existing) {
            // Reactivate if inactive
            if (!$existing->is_active) {
                $existing->update(['is_active' => true]);
            }
            return $existing;
        }
        
        // Create new assignment
        return CashAccountManager::create([
            'manager_id' => $this->id,
            'cash_account_id' => $cashAccountId,
            'assigned_at' => now(),
            'is_active' => true,
        ]);
    }
    
    /**
     * Remove this user as manager from cash account
     */
    public function removeFromCashAccount(int $cashAccountId): bool
    {
        return CashAccountManager::where('manager_id', $this->id)
            ->where('cash_account_id', $cashAccountId)
            ->update(['is_active' => false]);
    }
    
    /**
     * Get count of cash accounts managed by this user
     */
    public function getManagedCashAccountsCountAttribute(): int
    {
        return $this->managedCashAccounts()->count();
    }
}