<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Asset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'asset_code',
        'asset_name',
        'category',
        'acquisition_cost',
        'acquisition_date',
        'useful_life_months',
        'residual_value',
        'depreciation_per_month',
        'accumulated_depreciation',
        'book_value',
        'last_depreciation_date',
        'location',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_cost' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'depreciation_per_month' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'book_value' => 'decimal:2',
            'acquisition_date' => 'date',
            'last_depreciation_date' => 'date',
        ];
    }

    /**
     * Scope by category.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope active assets.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Get category display name.
     */
    public function getCategoryNameAttribute(): string
    {
        return match($this->category) {
            'land' => 'Tanah',
            'building' => 'Bangunan',
            'vehicle' => 'Kendaraan',
            'equipment' => 'Peralatan',
            'inventory' => 'Inventaris',
            default => $this->category,
        };
    }

    /**
     * Get status display name.
     */
    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'active' => 'Aktif',
            'damaged' => 'Rusak',
            'sold' => 'Terjual',
            'disposed' => 'Dihapuskan',
            default => $this->status,
        };
    }

    /**
     * Calculate depreciation per month (straight-line method).
     */
    public function calculateDepreciationPerMonth(): float
    {
        if ($this->useful_life_months == 0 || $this->category == 'land') {
            return 0;
        }

        $depreciableAmount = $this->acquisition_cost - $this->residual_value;
        return $depreciableAmount / $this->useful_life_months;
    }

    /**
     * Calculate and update depreciation.
     */
    public function calculateDepreciation(): void
    {
        // Calculate depreciation per month
        $this->depreciation_per_month = $this->calculateDepreciationPerMonth();

        // Calculate months since acquisition or last depreciation
        $startDate = $this->last_depreciation_date ?? $this->acquisition_date;
        $monthsPassed = $startDate->diffInMonths(now());

        if ($monthsPassed > 0) {
            // Calculate new accumulated depreciation
            $newDepreciation = $this->depreciation_per_month * $monthsPassed;
            $this->accumulated_depreciation += $newDepreciation;

            // Don't exceed depreciable amount
            $maxDepreciation = $this->acquisition_cost - $this->residual_value;
            if ($this->accumulated_depreciation > $maxDepreciation) {
                $this->accumulated_depreciation = $maxDepreciation;
            }

            // Update book value
            $this->book_value = $this->acquisition_cost - $this->accumulated_depreciation;

            // Update last depreciation date
            $this->last_depreciation_date = now();

            $this->save();
        }
    }

    /**
     * Generate asset code.
     */
    public static function generateAssetCode(string $category): string
    {
        $prefix = match($category) {
            'land' => 'LND',
            'building' => 'BLD',
            'vehicle' => 'VHC',
            'equipment' => 'EQP',
            'inventory' => 'INV',
            default => 'AST',
        };

        $year = now()->format('Y');
        $count = self::where('category', $category)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return "{$prefix}-{$year}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate all active assets depreciation.
     */
    public static function calculateAllDepreciation(): array
    {
        $assets = self::active()
            ->where('category', '!=', 'land')
            ->get();

        $results = [];
        $totalDepreciation = 0;

        foreach ($assets as $asset) {
            $oldAccumulated = $asset->accumulated_depreciation;
            $asset->calculateDepreciation();
            $newDepreciation = $asset->accumulated_depreciation - $oldAccumulated;

            if ($newDepreciation > 0) {
                $results[] = [
                    'asset' => $asset,
                    'depreciation_amount' => $newDepreciation,
                ];
                $totalDepreciation += $newDepreciation;
            }
        }

        return [
            'assets' => $results,
            'total_depreciation' => $totalDepreciation,
            'count' => count($results),
        ];
    }

    /**
     * Get depreciation schedule.
     */
    public function getDepreciationSchedule(): array
    {
        if ($this->useful_life_months == 0 || $this->category == 'land') {
            return [];
        }

        $schedule = [];
        $currentDate = $this->acquisition_date->copy();
        $remainingValue = $this->acquisition_cost;

        for ($month = 1; $month <= $this->useful_life_months; $month++) {
            $currentDate->addMonth();
            $remainingValue -= $this->depreciation_per_month;

            // Don't go below residual value
            if ($remainingValue < $this->residual_value) {
                $remainingValue = $this->residual_value;
            }

            $schedule[] = [
                'month' => $month,
                'date' => $currentDate->format('Y-m-d'),
                'depreciation' => $this->depreciation_per_month,
                'accumulated' => $this->depreciation_per_month * $month,
                'book_value' => $remainingValue,
            ];
        }

        return $schedule;
    }
}