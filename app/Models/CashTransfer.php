<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class CashTransfer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cash_transfers';

    protected $fillable = [
        'transfer_number',
        'from_cash_account_id',
        'to_cash_account_id',
        'amount',
        'transfer_date',
        'purpose',
        'notes',
        'journal_id',
        'created_by',
        'approved_by',
        'approved_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transfer_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    // ==================== RELATIONSHIPS ====================

    public function fromCashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'from_cash_account_id');
    }

    public function toCashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'to_cash_account_id');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
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

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('transfer_date', [$startDate, $endDate]);
    }

    public function scopeFromAccount($query, int $accountId)
    {
        return $query->where('from_cash_account_id', $accountId);
    }

    public function scopeToAccount($query, int $accountId)
    {
        return $query->where('to_cash_account_id', $accountId);
    }

    // ==================== ACCESSORS ====================

    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Menunggu Persetujuan',
            'approved' => 'Disetujui',
            'completed' => 'Selesai',
            'cancelled' => 'Dibatalkan',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'approved' => 'info',
            'completed' => 'success',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }

    public function getFormattedAmountAttribute(): string
    {
        return 'Rp ' . number_format($this->amount, 0, ',', '.');
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

    // ==================== BUSINESS LOGIC ====================

    /**
     * Generate transfer number.
     * Format: TRF/YY/MM/NNNN
     */
    public static function generateTransferNumber(): string
    {
        $date = now();
        $year = $date->format('y');
        $month = $date->format('m');
        
        $count = self::whereYear('created_at', $date->year)
            ->whereMonth('created_at', $date->month)
            ->count() + 1;
        
        return "TRF/{$year}/{$month}/" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Create new transfer.
     * 
     * VALIDATION:
     * - From & To account harus berbeda
     * - From account balance harus cukup
     * - Both accounts harus active
     */
    public static function createTransfer(array $data, int $createdBy): self
    {
        // Validate different accounts
        if ($data['from_cash_account_id'] === $data['to_cash_account_id']) {
            throw new \Exception('Tidak dapat transfer ke kas yang sama');
        }

        // Validate accounts exist and active
        $fromAccount = CashAccount::findOrFail($data['from_cash_account_id']);
        $toAccount = CashAccount::findOrFail($data['to_cash_account_id']);

        if (!$fromAccount->is_active || !$toAccount->is_active) {
            throw new \Exception('Kedua kas harus dalam status aktif');
        }

        // Validate sufficient balance
        if ($fromAccount->current_balance < $data['amount']) {
            throw new \Exception('Saldo kas sumber tidak mencukupi');
        }

        return self::create([
            'transfer_number' => self::generateTransferNumber(),
            'from_cash_account_id' => $data['from_cash_account_id'],
            'to_cash_account_id' => $data['to_cash_account_id'],
            'amount' => $data['amount'],
            'transfer_date' => $data['transfer_date'] ?? now(),
            'purpose' => $data['purpose'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $createdBy,
            'status' => 'pending',
        ]);
    }

    /**
     * Approve and complete transfer.
     * 
     * BUSINESS LOGIC:
     * 1. Update cash account balances
     * 2. Create auto journal entry
     * 3. Mark as completed
     */
    public function approveAndComplete(int $approvedBy): void
    {
        if (!$this->isPending()) {
            throw new \Exception('Hanya transfer berstatus pending yang dapat disetujui');
        }

        DB::transaction(function() use ($approvedBy) {
            // Re-validate balance
            if ($this->fromCashAccount->current_balance < $this->amount) {
                throw new \Exception('Saldo kas sumber tidak mencukupi');
            }

            // Update balances
            $this->fromCashAccount->updateBalance($this->amount, 'subtract');
            $this->toCashAccount->updateBalance($this->amount, 'add');

            // Create auto journal entry
            $journal = $this->createJournalEntry();

            // Update transfer status
            $this->update([
                'status' => 'completed',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
                'journal_id' => $journal->id,
            ]);

            // Log activity
            ActivityLog::createLog([
                'activity' => 'transfer_completed',
                'module' => 'cash_transfers',
                'description' => "Transfer {$this->transfer_number}: {$this->fromCashAccount->name} → {$this->toCashAccount->name} sebesar {$this->formatted_amount}",
            ]);
        });
    }

    /**
     * Create auto journal entry for transfer.
     * 
     * Jurnal:
     * Dr. Kas Tujuan (To Account)
     *   Cr. Kas Sumber (From Account)
     */
    private function createJournalEntry(): Journal
    {
        // TODO: Get proper chart of account IDs
        // For now, we'll use placeholder logic
        // This should be configured based on cash account mapping to COA
        
        $journal = Journal::create([
            'journal_number' => Journal::generateJournalNumber('special'),
            'journal_type' => 'special',
            'description' => "Transfer Kas: {$this->fromCashAccount->name} ke {$this->toCashAccount->name} - {$this->purpose}",
            'transaction_date' => $this->transfer_date,
            'created_by' => $this->approved_by,
            'is_auto_generated' => true,
            'is_editable' => false,
            'source_module' => 'cash_transfers',
            'reference_type' => 'App\Models\CashTransfer',
            'reference_id' => $this->id,
        ]);

        // Create journal details
        // TODO: Map cash accounts to proper COA
        // For now using placeholder IDs
        
        JournalDetail::create([
            'journal_id' => $journal->id,
            'chart_of_account_id' => 1, // TODO: Map to proper account
            'debit' => $this->amount,
            'credit' => 0,
            'description' => "Kas masuk: {$this->toCashAccount->name}",
        ]);

        JournalDetail::create([
            'journal_id' => $journal->id,
            'chart_of_account_id' => 2, // TODO: Map to proper account
            'debit' => 0,
            'credit' => $this->amount,
            'description' => "Kas keluar: {$this->fromCashAccount->name}",
        ]);

        // Calculate totals
        $journal->calculateTotals();

        return $journal;
    }

    /**
     * Cancel transfer.
     */
    public function cancel(int $userId, string $reason): void
    {
        if (!$this->isPending()) {
            throw new \Exception('Hanya transfer berstatus pending yang dapat dibatalkan');
        }

        $this->update([
            'status' => 'cancelled',
            'notes' => ($this->notes ? $this->notes . ' | ' : '') . "Dibatalkan: {$reason}",
        ]);

        ActivityLog::createLog([
            'activity' => 'transfer_cancelled',
            'module' => 'cash_transfers',
            'description' => "Transfer {$this->transfer_number} dibatalkan: {$reason}",
        ]);
    }

    /**
     * Get transfer summary.
     */
    public function getSummary(): array
    {
        return [
            'transfer_number' => $this->transfer_number,
            'from' => [
                'code' => $this->fromCashAccount->code,
                'name' => $this->fromCashAccount->name,
                'balance_before' => $this->fromCashAccount->current_balance + ($this->isCompleted() ? $this->amount : 0),
                'balance_after' => $this->fromCashAccount->current_balance,
            ],
            'to' => [
                'code' => $this->toCashAccount->code,
                'name' => $this->toCashAccount->name,
                'balance_before' => $this->toCashAccount->current_balance - ($this->isCompleted() ? $this->amount : 0),
                'balance_after' => $this->toCashAccount->current_balance,
            ],
            'amount' => $this->amount,
            'formatted_amount' => $this->formatted_amount,
            'date' => $this->transfer_date->format('d F Y'),
            'purpose' => $this->purpose,
            'status' => $this->status_name,
            'created_by' => $this->creator->full_name,
            'approved_by' => $this->approver?->full_name,
        ];
    }
}