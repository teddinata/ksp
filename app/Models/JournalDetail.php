<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_id',
        'chart_of_account_id',
        'debit',
        'credit',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /**
     * Get journal.
     */
    public function journal()
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    /**
     * Get chart of account.
     */
    public function chartOfAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    /**
     * Scope by journal.
     */
    public function scopeByJournal($query, int $journalId)
    {
        return $query->where('journal_id', $journalId);
    }

    /**
     * Scope by account.
     */
    public function scopeByAccount($query, int $accountId)
    {
        return $query->where('chart_of_account_id', $accountId);
    }

    /**
     * Scope debit entries.
     */
    public function scopeDebits($query)
    {
        return $query->where('debit', '>', 0);
    }

    /**
     * Scope credit entries.
     */
    public function scopeCredits($query)
    {
        return $query->where('credit', '>', 0);
    }
}