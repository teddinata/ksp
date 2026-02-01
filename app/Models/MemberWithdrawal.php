<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class MemberWithdrawal extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'member_withdrawals';

    protected $fillable = [
        'resignation_id',
        'user_id',
        'principal_amount',
        'mandatory_amount',
        'voluntary_amount',
        'holiday_amount',
        'total_withdrawal',
        'payment_method',
        'bank_name',
        'account_number',
        'account_holder_name',
        'transfer_reference',
        'cash_account_id',
        'withdrawal_date',
        'processed_by',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'mandatory_amount' => 'decimal:2',
            'voluntary_amount' => 'decimal:2',
            'holiday_amount' => 'decimal:2',
            'total_withdrawal' => 'decimal:2',
            'withdrawal_date' => 'date',
        ];
    }

    // ==================== RELATIONSHIPS ====================

    public function resignation()
    {
        return $this->belongsTo(MemberResignation::class, 'resignation_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ==================== ACCESSORS ====================

    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Menunggu Pencairan',
            'completed' => 'Sudah Dicairkan',
            'cancelled' => 'Dibatalkan',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'completed' => 'success',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }

    public function getPaymentMethodNameAttribute(): string
    {
        return match($this->payment_method) {
            'cash' => 'Tunai',
            'transfer' => 'Transfer Bank',
            'check' => 'Cek',
            default => $this->payment_method,
        };
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Process withdrawal after resignation approval.
     * 
     * BUSINESS LOGIC:
     * 1. Validate resignation is approved
     * 2. Create withdrawal record
     * 3. Deduct cash account balance
     * 4. Mark resignation as completed
     * 5. Set member status to inactive
     * 6. Create journal entry (future)
     */
    public static function processWithdrawal(
        MemberResignation $resignation,
        int $cashAccountId,
        string $paymentMethod,
        int $processedBy,
        array $paymentDetails = [],
        ?string $notes = null
    ): self {
        // Validate
        if (!$resignation->isApproved()) {
            throw new \Exception('Resignation must be approved before withdrawal');
        }

        if ($resignation->withdrawal()->exists()) {
            throw new \Exception('Withdrawal already processed for this resignation');
        }

        $cashAccount = CashAccount::findOrFail($cashAccountId);

        // Check cash account balance
        if ($cashAccount->current_balance < $resignation->total_savings) {
            throw new \Exception('Insufficient cash account balance');
        }

        return DB::transaction(function() use (
            $resignation, 
            $cashAccountId, 
            $paymentMethod, 
            $processedBy, 
            $paymentDetails,
            $notes,
            $cashAccount
        ) {
            // Create withdrawal record
            $withdrawal = self::create([
                'resignation_id' => $resignation->id,
                'user_id' => $resignation->user_id,
                'principal_amount' => $resignation->principal_savings_balance,
                'mandatory_amount' => $resignation->mandatory_savings_balance,
                'voluntary_amount' => $resignation->voluntary_savings_balance,
                'holiday_amount' => $resignation->holiday_savings_balance,
                'total_withdrawal' => $resignation->total_savings,
                'payment_method' => $paymentMethod,
                'bank_name' => $paymentDetails['bank_name'] ?? null,
                'account_number' => $paymentDetails['account_number'] ?? null,
                'account_holder_name' => $paymentDetails['account_holder_name'] ?? null,
                'transfer_reference' => $paymentDetails['transfer_reference'] ?? null,
                'cash_account_id' => $cashAccountId,
                'withdrawal_date' => now(),
                'processed_by' => $processedBy,
                'notes' => $notes,
                'status' => 'completed',
            ]);

            // Update cash account balance
            $cashAccount->updateBalance($resignation->total_savings, 'subtract');

            // Complete resignation (also sets member to inactive)
            $resignation->complete();

            // Log activity
            ActivityLog::createLog([
                'activity' => 'withdrawal_processed',
                'module' => 'member_withdrawals',
                'cash_account_id' => $cashAccountId,
                'description' => "Pencairan simpanan untuk {$resignation->user->full_name}: Rp " . 
                                number_format($resignation->total_savings, 0, ',', '.'),
            ]);

            // TODO: Create journal entry
            // Journal::createWithdrawalEntry($withdrawal);

            return $withdrawal->fresh(['user', 'resignation', 'cashAccount']);
        });
    }

    /**
     * Get withdrawal summary.
     */
    public function getSummary(): array
    {
        return [
            'member' => [
                'name' => $this->user->full_name,
                'employee_id' => $this->user->employee_id,
                'member_number' => $this->user->member_number,
            ],
            'withdrawal' => [
                'date' => $this->withdrawal_date->format('d F Y'),
                'method' => $this->payment_method_name,
                'status' => $this->status_name,
            ],
            'amounts' => [
                'principal' => $this->principal_amount,
                'mandatory' => $this->mandatory_amount,
                'voluntary' => $this->voluntary_amount,
                'holiday' => $this->holiday_amount,
                'total' => $this->total_withdrawal,
            ],
            'payment_details' => [
                'method' => $this->payment_method,
                'bank_name' => $this->bank_name,
                'account_number' => $this->account_number,
                'account_holder' => $this->account_holder_name,
                'reference' => $this->transfer_reference,
            ],
            'cash_account' => [
                'code' => $this->cashAccount->code,
                'name' => $this->cashAccount->name,
            ],
        ];
    }
}