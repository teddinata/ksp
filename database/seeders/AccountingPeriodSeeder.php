<?php

namespace Database\Seeders;

use App\Models\AccountingPeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AccountingPeriodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@ksu-ceria.test')->first();

        // Create periods for last 3 months (CLOSED)
        for ($i = 3; $i >= 1; $i--) {
            $startDate = Carbon::now()->subMonths($i)->startOfMonth();
            $endDate = Carbon::now()->subMonths($i)->endOfMonth();

            AccountingPeriod::create([
                'period_name' => AccountingPeriod::generatePeriodName($startDate, $endDate),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_closed' => true,
                'closed_by' => $admin ? $admin->id : null,
                'closed_at' => $endDate->copy()->addDays(5)->setTime(17, 0),
            ]);
        }

        // Create current month period (OPEN & ACTIVE)
        $currentStart = Carbon::now()->startOfMonth();
        $currentEnd = Carbon::now()->endOfMonth();

        AccountingPeriod::create([
            'period_name' => AccountingPeriod::generatePeriodName($currentStart, $currentEnd),
            'start_date' => $currentStart,
            'end_date' => $currentEnd,
            'is_closed' => false,
        ]);

        // Create next month period (OPEN but not yet active)
        $nextStart = Carbon::now()->addMonth()->startOfMonth();
        $nextEnd = Carbon::now()->addMonth()->endOfMonth();

        AccountingPeriod::create([
            'period_name' => AccountingPeriod::generatePeriodName($nextStart, $nextEnd),
            'start_date' => $nextStart,
            'end_date' => $nextEnd,
            'is_closed' => false,
        ]);

        // Create Q1 2025 (if not current quarter)
        $q1Start = Carbon::create(2025, 1, 1);
        $q1End = Carbon::create(2025, 3, 31);

        if ($q1Start->isPast()) {
            AccountingPeriod::create([
                'period_name' => 'Q1 2025',
                'start_date' => $q1Start,
                'end_date' => $q1End,
                'is_closed' => $q1End->isPast(),
                'closed_by' => ($q1End->isPast() && $admin) ? $admin->id : null,
                'closed_at' => $q1End->isPast() ? $q1End->copy()->addDays(5)->setTime(17, 0) : null,
            ]);
        }

        // Create FY 2025
        $fyStart = Carbon::create(2025, 1, 1);
        $fyEnd = Carbon::create(2025, 12, 31);

        AccountingPeriod::create([
            'period_name' => 'FY 2025',
            'start_date' => $fyStart,
            'end_date' => $fyEnd,
            'is_closed' => false,
        ]);
    }
}