<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class AccountingPeriod extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'accounting_periods';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'period_name',
        'start_date',
        'end_date',
        'is_closed',
        'closed_by',
        'closed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_closed' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Get the user who closed this period.
     */
    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Get journals in this period.
     */
    public function journals()
    {
        return $this->hasMany(Journal::class, 'accounting_period_id');
    }

    /**
     * Scope query for open periods only.
     */
    public function scopeOpen($query)
    {
        return $query->where('is_closed', false);
    }

    /**
     * Scope query for closed periods only.
     */
    public function scopeClosed($query)
    {
        return $query->where('is_closed', true);
    }

    /**
     * Scope query for current active period.
     */
    public function scopeActive($query)
    {
        $today = now()->toDateString();
        return $query->where('start_date', '<=', $today)
                    ->where('end_date', '>=', $today)
                    ->where('is_closed', false);
    }

    /**
     * Scope query for periods within date range.
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->where(function($q) use ($startDate, $endDate) {
            $q->whereBetween('start_date', [$startDate, $endDate])
              ->orWhereBetween('end_date', [$startDate, $endDate])
              ->orWhere(function($q2) use ($startDate, $endDate) {
                  $q2->where('start_date', '<=', $startDate)
                     ->where('end_date', '>=', $endDate);
              });
        });
    }

    /**
     * Scope query for specific year.
     */
    public function scopeForYear($query, int $year)
    {
        return $query->whereYear('start_date', $year)
                    ->orWhereYear('end_date', $year);
    }

    /**
     * Check if period is closed.
     */
    public function isClosed(): bool
    {
        return $this->is_closed;
    }

    /**
     * Check if period is currently active.
     */
    public function isActive(): bool
    {
        $today = now()->toDateString();
        return $this->start_date <= $today 
            && $this->end_date >= $today 
            && !$this->is_closed;
    }

    /**
     * Check if period has journals.
     */
    public function hasJournals(): bool
    {
        return $this->journals()->exists();
    }

    /**
     * Get period duration in days.
     */
    public function getDurationAttribute(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Get period type based on duration.
     */
    public function getPeriodTypeAttribute(): string
    {
        $days = $this->duration;
        
        if ($days <= 31) {
            return 'Monthly';
        } elseif ($days <= 92) {
            return 'Quarterly';
        } elseif ($days <= 366) {
            return 'Yearly';
        } else {
            return 'Custom';
        }
    }

    /**
     * Get formatted period range.
     */
    public function getPeriodRangeAttribute(): string
    {
        return $this->start_date->format('d M Y') . ' - ' . $this->end_date->format('d M Y');
    }

    /**
     * Close this period.
     */
    public function close(int $userId): void
    {
        $this->update([
            'is_closed' => true,
            'closed_by' => $userId,
            'closed_at' => now(),
        ]);

        // Lock all journals in this period (future implementation)
        // $this->journals()->update(['is_locked' => true]);
    }

    /**
     * Reopen this period.
     */
    public function reopen(): void
    {
        $this->update([
            'is_closed' => false,
            'closed_by' => null,
            'closed_at' => null,
        ]);

        // Unlock all journals in this period (future implementation)
        // $this->journals()->update(['is_locked' => false]);
    }

    /**
     * Generate period name from dates.
     * 
     * Examples:
     * - "January 2025" for monthly
     * - "Q1 2025" for quarterly
     * - "FY 2025" for yearly
     */
    public static function generatePeriodName(Carbon $startDate, Carbon $endDate): string
    {
        $days = $startDate->diffInDays($endDate) + 1;

        // Monthly period
        if ($days <= 31) {
            return $startDate->format('F Y');
        }

        // Quarterly period
        if ($days <= 92) {
            $quarter = ceil($startDate->month / 3);
            return "Q{$quarter} {$startDate->year}";
        }

        // Yearly period
        if ($days <= 366) {
            return "FY {$startDate->year}";
        }

        // Custom period
        return $startDate->format('M Y') . ' - ' . $endDate->format('M Y');
    }

    /**
     * Check if dates overlap with existing periods.
     */
    public static function hasOverlap(Carbon $startDate, Carbon $endDate, ?int $excludeId = null): bool
    {
        $query = self::betweenDates($startDate, $endDate);
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Get or create period for a given date.
     */
    public static function getOrCreateForDate(Carbon $date): self
    {
        // Try to find existing period
        $period = self::where('start_date', '<=', $date)
                     ->where('end_date', '>=', $date)
                     ->first();

        if ($period) {
            return $period;
        }

        // Create new monthly period
        $startDate = $date->copy()->startOfMonth();
        $endDate = $date->copy()->endOfMonth();

        return self::create([
            'period_name' => self::generatePeriodName($startDate, $endDate),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_closed' => false,
        ]);
    }
}