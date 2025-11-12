<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'activity_logs';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'activity',
        'module',
        'cash_account_id',
        'description',
        'ip_address',
        'user_agent',
        'old_data',
        'new_data',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_data' => 'array',
            'new_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the user who performed the activity.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the cash account if related.
     */
    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    /**
     * Scope query by user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope query by module.
     */
    public function scopeByModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Scope query by activity.
     */
    public function scopeByActivity($query, string $activity)
    {
        return $query->where('activity', $activity);
    }

    /**
     * Scope query for date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Create activity log.
     */
    public static function createLog(array $data): void
    {
        try {
            self::create([
                'user_id' => $data['user_id'] ?? auth()->id(),
                'activity' => $data['activity'],
                'module' => $data['module'] ?? null,
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'description' => $data['description'] ?? null,
                'ip_address' => $data['ip_address'] ?? request()->ip(),
                'user_agent' => $data['user_agent'] ?? request()->userAgent(),
                'old_data' => $data['old_data'] ?? null,
                'new_data' => $data['new_data'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Silent fail - logging shouldn't break the app
            \Log::error('Failed to create activity log: ' . $e->getMessage());
        }
    }

    /**
     * Get activity display name.
     */
    public function getActivityNameAttribute(): string
    {
        return match($this->activity) {
            'login' => 'Login ke Sistem',
            'logout' => 'Logout dari Sistem',
            'create' => 'Membuat Data Baru',
            'update' => 'Mengubah Data',
            'delete' => 'Menghapus Data',
            'approve' => 'Menyetujui',
            'reject' => 'Menolak',
            'distribute' => 'Mendistribusikan',
            'payment' => 'Pembayaran',
            default => $this->activity,
        };
    }

    /**
     * Get module display name.
     */
    public function getModuleNameAttribute(): string
    {
        return match($this->module) {
            'users' => 'Pengguna',
            'savings' => 'Simpanan',
            'loans' => 'Pinjaman',
            'installments' => 'Cicilan',
            'service_allowances' => 'Jasa Pelayanan',
            'gifts' => 'Hadiah',
            'cash_accounts' => 'Kas',
            'accounting_periods' => 'Periode Akuntansi',
            'chart_of_accounts' => 'Akun',
            default => $this->module ?? 'Sistem',
        };
    }
}