<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Gift extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'gifts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'gift_type',
        'gift_name',
        'gift_value',
        'distribution_date',
        'status',
        'notes',
        'distributed_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gift_value' => 'decimal:2',
            'distribution_date' => 'date',
        ];
    }

    /**
     * Get the user who received the gift.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the distributor.
     */
    public function distributedBy()
    {
        return $this->belongsTo(User::class, 'distributed_by');
    }

    /**
     * Scope query by gift type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('gift_type', $type);
    }

    /**
     * Scope query by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope query for pending gifts.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope query for distributed gifts.
     */
    public function scopeDistributed($query)
    {
        return $query->where('status', 'distributed');
    }

    /**
     * Scope query by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope query by year.
     */
    public function scopeByYear($query, int $year)
    {
        return $query->whereYear('distribution_date', $year);
    }

    /**
     * Scope query by date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('distribution_date', [$startDate, $endDate]);
    }

    /**
     * Get gift type display name.
     */
    public function getTypeNameAttribute(): string
    {
        return match($this->gift_type) {
            'holiday' => 'Hadiah Hari Raya',
            'achievement' => 'Penghargaan Prestasi',
            'birthday' => 'Hadiah Ulang Tahun',
            'special_event' => 'Acara Khusus',
            'loyalty' => 'Hadiah Loyalitas',
            default => $this->gift_type,
        };
    }

    /**
     * Get status display name.
     */
    public function getStatusNameAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Menunggu Distribusi',
            'distributed' => 'Sudah Diberikan',
            'cancelled' => 'Dibatalkan',
            default => $this->status,
        };
    }

    /**
     * Get status color.
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'distributed' => 'success',
            'cancelled' => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Check if gift is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if gift is distributed.
     */
    public function isDistributed(): bool
    {
        return $this->status === 'distributed';
    }

    /**
     * Mark as distributed.
     */
    public function markAsDistributed(int $distributedBy, ?string $notes = null): void
    {
        $this->update([
            'status' => 'distributed',
            'distributed_by' => $distributedBy,
            'notes' => $notes ?? $this->notes,
        ]);
    }

    /**
     * Distribute gifts to all active members.
     *
     * @param string $giftType
     * @param string $giftName
     * @param float $giftValue
     * @param string $distributionDate
     * @param int $distributedBy
     * @param array $userIds Optional specific user IDs, otherwise all active members
     * @return array
     */
    public static function distributeToMembers(
        string $giftType,
        string $giftName,
        float $giftValue,
        string $distributionDate,
        int $distributedBy,
        ?array $userIds = null
    ): array {
        // Get members
        if ($userIds) {
            $members = User::whereIn('id', $userIds)->members()->active()->get();
        } else {
            $members = User::members()->active()->get();
        }

        $distributed = [];
        $totalValue = 0;

        foreach ($members as $member) {
            $gift = self::create([
                'user_id' => $member->id,
                'gift_type' => $giftType,
                'gift_name' => $giftName,
                'gift_value' => $giftValue,
                'distribution_date' => $distributionDate,
                'status' => 'pending',
                'distributed_by' => $distributedBy,
            ]);

            $distributed[] = $gift;
            $totalValue += $giftValue;
        }

        return [
            'gift_type' => $giftType,
            'gift_name' => $giftName,
            'members_count' => count($distributed),
            'total_value' => $totalValue,
            'gifts' => $distributed,
        ];
    }

    /**
     * Get total value by type.
     */
    public static function getTotalValueByType(string $type, ?int $year = null): float
    {
        $query = self::where('gift_type', $type)
            ->where('status', 'distributed');

        if ($year) {
            $query->whereYear('distribution_date', $year);
        }

        return $query->sum('gift_value');
    }

    /**
     * Get total value for all gifts.
     */
    public static function getTotalValue(?int $year = null): float
    {
        $query = self::where('status', 'distributed');

        if ($year) {
            $query->whereYear('distribution_date', $year);
        }

        return $query->sum('gift_value');
    }

    /**
     * Get member's total gifts for a year.
     */
    public static function getMemberTotalForYear(int $userId, int $year): float
    {
        return self::where('user_id', $userId)
            ->where('status', 'distributed')
            ->whereYear('distribution_date', $year)
            ->sum('gift_value');
    }

    /**
     * Get gift statistics.
     */
    public static function getStatistics(?int $year = null): array
    {
        $query = self::query();

        if ($year) {
            $query->whereYear('distribution_date', $year);
        }

        return [
            'total_gifts' => $query->count(),
            'total_value' => $query->where('status', 'distributed')->sum('gift_value'),
            'pending_count' => $query->where('status', 'pending')->count(),
            'distributed_count' => $query->where('status', 'distributed')->count(),
            'by_type' => [
                'holiday' => self::getTotalValueByType('holiday', $year),
                'achievement' => self::getTotalValueByType('achievement', $year),
                'birthday' => self::getTotalValueByType('birthday', $year),
                'special_event' => self::getTotalValueByType('special_event', $year),
                'loyalty' => self::getTotalValueByType('loyalty', $year),
            ],
        ];
    }
}