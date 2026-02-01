<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class MemberResignation extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'member_resignations';

    protected $fillable = [
        'user_id',
        'reason',
        'resignation_date',
        'principal_savings_balance',
        'mandatory_savings_balance',
        'voluntary_savings_balance',
        'holiday_savings_balance',
        'total_savings',
        'has_active_loans',
        'active_loans_count',
        'total_loan_outstanding',
        'status',
        'processed_by',
        'processed_at',
        'rejection_reason',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'resignation_date' => 'date',
            'principal_savings_balance' => 'decimal:2',
            'mandatory_savings_balance' => 'decimal:2',
            'voluntary_savings_balance' => 'decimal:2',
            'holiday_savings_balance' => 'decimal:2',
            'total_savings' => 'decimal:2',
            'has_active_loans' => 'boolean',
            'active_loans_count' => 'integer',
            'total_loan_outstanding' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the member who is resigning.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the admin who processed this.
     */
    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Get the withdrawal record (if completed).
     */
    public function withdrawal()
    {
        return $this->hasOne(MemberWithdrawal::class, 'resignation_id');
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ==================== ACCESSORS ====================

    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Menunggu Persetujuan',
            'approved' => 'Disetujui - Menunggu Pencairan',
            'completed' => 'Selesai',
            'rejected' => 'Ditolak',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'approved' => 'info',
            'completed' => 'success',
            'rejected' => 'danger',
            default => 'secondary',
        };
    }

    // ==================== STATUS CHECKERS ====================

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Create resignation request.
     * 
     * VALIDATION:
     * - Member harus tidak punya pinjaman aktif
     * - Calculate semua saldo simpanan
     */
    public static function createRequest(User $member, string $reason): self
    {
        // Validate: no active loans
        $activeLoans = Loan::where('user_id', $member->id)
            ->whereIn('status', ['disbursed', 'active'])
            ->get();

        if ($activeLoans->count() > 0) {
            throw new \Exception('Tidak dapat mengajukan keluar. Masih memiliki pinjaman aktif yang harus diselesaikan terlebih dahulu.');
        }

        // Calculate savings balances
        $principalBalance = Saving::getTotalByType($member->id, 'principal');
        $mandatoryBalance = Saving::getTotalByType($member->id, 'mandatory');
        $voluntaryBalance = Saving::getTotalByType($member->id, 'voluntary');
        $holidayBalance = Saving::getTotalByType($member->id, 'holiday');
        $totalSavings = $principalBalance + $mandatoryBalance + $voluntaryBalance + $holidayBalance;

        return self::create([
            'user_id' => $member->id,
            'reason' => $reason,
            'resignation_date' => now(),
            'principal_savings_balance' => $principalBalance,
            'mandatory_savings_balance' => $mandatoryBalance,
            'voluntary_savings_balance' => $voluntaryBalance,
            'holiday_savings_balance' => $holidayBalance,
            'total_savings' => $totalSavings,
            'has_active_loans' => false,
            'active_loans_count' => 0,
            'total_loan_outstanding' => 0,
            'status' => 'pending',
        ]);
    }

    /**
     * Approve resignation.
     */
    public function approve(int $adminId, ?string $notes = null): void
    {
        if (!$this->isPending()) {
            throw new \Exception('Hanya pengajuan berstatus pending yang bisa disetujui.');
        }

        // Re-validate: no active loans
        $hasActiveLoans = Loan::where('user_id', $this->user_id)
            ->whereIn('status', ['disbursed', 'active'])
            ->exists();

        if ($hasActiveLoans) {
            throw new \Exception('Member masih memiliki pinjaman aktif.');
        }

        $this->update([
            'status' => 'approved',
            'processed_by' => $adminId,
            'processed_at' => now(),
            'admin_notes' => $notes,
        ]);
    }

    /**
     * Reject resignation.
     */
    public function reject(int $adminId, string $rejectionReason): void
    {
        if (!$this->isPending()) {
            throw new \Exception('Hanya pengajuan berstatus pending yang bisa ditolak.');
        }

        $this->update([
            'status' => 'rejected',
            'processed_by' => $adminId,
            'processed_at' => now(),
            'rejection_reason' => $rejectionReason,
        ]);
    }

    /**
     * Complete resignation after withdrawal.
     * 
     * Called by MemberWithdrawal when withdrawal is completed.
     */
    public function complete(): void
    {
        if (!$this->isApproved()) {
            throw new \Exception('Hanya pengajuan yang sudah disetujui yang bisa diselesaikan.');
        }

        DB::transaction(function() {
            // Update status
            $this->update(['status' => 'completed']);

            // Update member status to inactive
            $this->user->update([
                'status' => 'inactive',
                'resignation_reason' => $this->reason,
            ]);

            // Log activity
            ActivityLog::createLog([
                'activity' => 'member_resigned',
                'module' => 'members',
                'description' => "Member {$this->user->full_name} telah keluar dari koperasi. Total pencairan: Rp " . 
                                number_format($this->total_savings, 0, ',', '.'),
                'old_data' => ['status' => 'active'],
                'new_data' => ['status' => 'inactive'],
            ]);
        });
    }

    /**
     * Get summary for display.
     */
    public function getSummary(): array
    {
        return [
            'member' => [
                'id' => $this->user->id,
                'name' => $this->user->full_name,
                'employee_id' => $this->user->employee_id,
                'member_number' => $this->user->member_number,
            ],
            'resignation' => [
                'date' => $this->resignation_date->format('d F Y'),
                'reason' => $this->reason,
                'status' => $this->status_name,
            ],
            'savings' => [
                'principal' => $this->principal_savings_balance,
                'mandatory' => $this->mandatory_savings_balance,
                'voluntary' => $this->voluntary_savings_balance,
                'holiday' => $this->holiday_savings_balance,
                'total' => $this->total_savings,
            ],
            'loans' => [
                'has_active' => $this->has_active_loans,
                'count' => $this->active_loans_count,
                'outstanding' => $this->total_loan_outstanding,
            ],
            'can_proceed' => !$this->has_active_loans && $this->total_savings > 0,
        ];
    }
}