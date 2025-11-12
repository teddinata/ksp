<?php

namespace Database\Seeders;

use App\Models\ServiceAllowance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ServiceAllowanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::where('email', 'admin@ksu-ceria.test')->first();
        
        if (!$admin) {
            $this->command->warn('Admin user not found. Run UserSeeder first.');
            return;
        }

        // Distribute for last 3 months
        $months = [
            ['month' => 10, 'year' => 2024], // October 2024
            ['month' => 11, 'year' => 2024], // November 2024
            ['month' => 12, 'year' => 2024], // December 2024
        ];

        foreach ($months as $index => $period) {
            try {
                // Distribute service allowances
                $result = ServiceAllowance::distributeToMembers(
                    $period['month'],
                    $period['year'],
                    $admin->id,
                    [
                        'base_amount' => 50000,
                        'savings_rate' => 1.0,
                        'loan_rate' => 10.0,
                    ]
                );

                $this->command->info("Distributed for {$result['period']}: {$result['members_count']} members, Total: Rp " . number_format($result['total_amount'], 0, ',', '.'));

                // Mark some as paid (older months are paid)
                if ($index < 2) { // October and November
                    foreach ($result['allowances'] as $allowance) {
                        $allowance->markAsPaid($admin->id, 'Pembayaran jasa pelayanan bulan ' . Carbon::create($period['year'], $period['month'], 1)->format('F Y'));
                    }
                    $this->command->info("  -> All marked as paid");
                } else {
                    // December: Mark only 50% as paid
                    $halfway = ceil(count($result['allowances']) / 2);
                    foreach (array_slice($result['allowances'], 0, $halfway) as $allowance) {
                        $allowance->markAsPaid($admin->id, 'Pembayaran jasa pelayanan bulan Desember 2024');
                    }
                    $this->command->info("  -> {$halfway} marked as paid, rest pending");
                }

            } catch (\Exception $e) {
                $this->command->error("Failed to distribute for {$period['month']}/{$period['year']}: {$e->getMessage()}");
            }
        }
    }
}