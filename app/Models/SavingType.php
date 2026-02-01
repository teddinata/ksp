<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavingType extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'saving_types';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_mandatory',
        'is_withdrawable',
        'minimum_amount',
        'maximum_amount',
        'has_interest',
        'default_interest_rate',
        'is_active',
        'display_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'is_withdrawable' => 'boolean',
            'minimum_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
            'has_interest' => 'boolean',
            'default_interest_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Get all savings using this type.
     */
    public function savings()
    {
        return $this->hasMany(Saving::class, 'saving_type_id');
    }

    /**
     * Get the admin who created this type.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    public function scopeOptional($query)
    {
        return $query->where('is_mandatory', false);
    }

    public function scopeWithdrawable($query)
    {
        return $query->where('is_withdrawable', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    // ==================== ACCESSORS ====================

    /**
     * Get formatted minimum amount.
     */
    public function getFormattedMinimumAttribute(): string
    {
        return 'Rp ' . number_format($this->minimum_amount, 0, ',', '.');
    }

    /**
     * Get formatted maximum amount.
     */
    public function getFormattedMaximumAttribute(): ?string
    {
        if (!$this->maximum_amount) {
            return 'Tidak terbatas';
        }
        return 'Rp ' . number_format($this->maximum_amount, 0, ',', '.');
    }

    /**
     * Get type characteristics summary.
     */
    public function getCharacteristicsAttribute(): string
    {
        $chars = [];
        
        if ($this->is_mandatory) {
            $chars[] = 'Wajib';
        }
        
        if ($this->is_withdrawable) {
            $chars[] = 'Dapat Ditarik';
        } else {
            $chars[] = 'Tidak Dapat Ditarik';
        }
        
        if ($this->has_interest) {
            $chars[] = 'Berbunga ' . $this->default_interest_rate . '%';
        } else {
            $chars[] = 'Tanpa Bunga';
        }
        
        return implode(' • ', $chars);
    }

    // ==================== VALIDATION METHODS ====================

    /**
     * Validate amount against this type's rules.
     */
    public function validateAmount(float $amount): array
    {
        $errors = [];

        if ($amount < $this->minimum_amount) {
            $errors[] = "Minimal simpanan {$this->name} adalah {$this->formatted_minimum}";
        }

        if ($this->maximum_amount && $amount > $this->maximum_amount) {
            $errors[] = "Maksimal simpanan {$this->name} adalah {$this->formatted_maximum}";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check if member can withdraw from this type.
     */
    public function canWithdraw(): bool
    {
        return $this->is_withdrawable && $this->is_active;
    }

    // ==================== STATIC METHODS ====================

    /**
     * Get default saving types (POKOK, WAJIB, SUKARELA, HARIRAYA).
     */
    public static function getDefaultTypes(): array
    {
        return [
            'POKOK' => self::where('code', 'POKOK')->first(),
            'WAJIB' => self::where('code', 'WAJIB')->first(),
            'SUKARELA' => self::where('code', 'SUKARELA')->first(),
            'HARIRAYA' => self::where('code', 'HARIRAYA')->first(),
        ];
    }

    /**
     * Get mandatory types.
     */
    public static function getMandatoryTypes()
    {
        return self::active()->mandatory()->ordered()->get();
    }

    /**
     * Get optional types.
     */
    public static function getOptionalTypes()
    {
        return self::active()->optional()->ordered()->get();
    }

    /**
     * Create new saving type.
     */
    public static function createType(array $data, int $createdBy): self
    {
        // Auto-generate code if not provided
        if (!isset($data['code'])) {
            $data['code'] = strtoupper(str_replace(' ', '_', $data['name']));
        }

        // Set display order if not provided
        if (!isset($data['display_order'])) {
            $data['display_order'] = self::max('display_order') + 1;
        }

        $data['created_by'] = $createdBy;

        return self::create($data);
    }

    /**
     * Get total savings by this type.
     */
    public function getTotalSavings(): float
    {
        return $this->savings()
            ->where('status', 'approved')
            ->sum('final_amount');
    }

    /**
     * Get member count using this type.
     */
    public function getMemberCount(): int
    {
        return $this->savings()
            ->where('status', 'approved')
            ->distinct('user_id')
            ->count('user_id');
    }

    /**
     * Get statistics for this type.
     */
    public function getStatistics(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'total_savings' => $this->getTotalSavings(),
            'member_count' => $this->getMemberCount(),
            'transaction_count' => $this->savings()->where('status', 'approved')->count(),
            'characteristics' => $this->characteristics,
            'is_active' => $this->is_active,
        ];
    }
}