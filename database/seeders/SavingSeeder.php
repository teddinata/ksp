<?php

namespace Database\Seeders;

use App\Models\Saving;
use App\Models\User;
use App\Models\CashAccount;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SavingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get users and cash accounts
        $admin = User::where('email', 'admin@ksu-ceria.test')->first();
        $members = User::where('role', 'anggota')->get();
        $kasUmum = CashAccount::where('code', 'KAS-I')->first();

        if (!$kasUmum || $members->isEmpty()) {
            $this->command->warn('Required data not found. Run UserSeeder and CashAccountSeeder first.');
            return;
        }

        // Interest rate for savings (8%)
        $interestRate = 8.0;

        foreach ($members as $member) {
            // 1. Principal Savings (Simpanan Pokok) - One time
            $principalAmount = 100000; // 100k
            Saving::create([
                'user_id' => $member->id,
                'cash_account_id' => $kasUmum->id,
                'savings_type' => 'principal',
                'amount' => $principalAmount,
                'interest_percentage' => $interestRate,
                'final_amount' => Saving::calculateFinalAmount($principalAmount, $interestRate),
                'transaction_date' => Carbon::now()->subMonths(6),
                'status' => 'approved',
                'notes' => 'Simpanan Pokok saat bergabung',
                'approved_by' => $admin ? $admin->id : null,
            ]);

            // 2. Mandatory Savings (Simpanan Wajib) - Monthly for last 6 months
            $mandatoryAmount = 50000; // 50k per month
            for ($i = 6; $i >= 1; $i--) {
                Saving::create([
                    'user_id' => $member->id,
                    'cash_account_id' => $kasUmum->id,
                    'savings_type' => 'mandatory',
                    'amount' => $mandatoryAmount,
                    'interest_percentage' => $interestRate,
                    'final_amount' => Saving::calculateFinalAmount($mandatoryAmount, $interestRate),
                    'transaction_date' => Carbon::now()->subMonths($i)->startOfMonth(),
                    'status' => 'approved',
                    'notes' => 'Simpanan Wajib bulan ' . Carbon::now()->subMonths($i)->format('F Y'),
                    'approved_by' => $admin ? $admin->id : null,
                ]);
            }

            // 3. Voluntary Savings (Simpanan Sukarela) - Random 2-3 times
            $voluntaryTransactions = rand(2, 3);
            for ($i = 0; $i < $voluntaryTransactions; $i++) {
                $voluntaryAmount = rand(100, 500) * 1000; // 100k - 500k
                Saving::create([
                    'user_id' => $member->id,
                    'cash_account_id' => $kasUmum->id,
                    'savings_type' => 'voluntary',
                    'amount' => $voluntaryAmount,
                    'interest_percentage' => $interestRate,
                    'final_amount' => Saving::calculateFinalAmount($voluntaryAmount, $interestRate),
                    'transaction_date' => Carbon::now()->subDays(rand(30, 150)),
                    'status' => 'approved',
                    'notes' => 'Simpanan Sukarela',
                    'approved_by' => $admin ? $admin->id : null,
                ]);
            }

            // 4. Holiday Savings (Simpanan Hari Raya) - Occasional
            if (rand(0, 1)) { // 50% chance
                $holidayAmount = 200000; // 200k
                Saving::create([
                    'user_id' => $member->id,
                    'cash_account_id' => $kasUmum->id,
                    'savings_type' => 'holiday',
                    'amount' => $holidayAmount,
                    'interest_percentage' => $interestRate,
                    'final_amount' => Saving::calculateFinalAmount($holidayAmount, $interestRate),
                    'transaction_date' => Carbon::now()->subMonths(rand(1, 4)),
                    'status' => 'approved',
                    'notes' => 'Simpanan Hari Raya',
                    'approved_by' => $admin ? $admin->id : null,
                ]);
            }
        }

        // Create some pending transactions
        if ($members->count() > 0) {
            $member = $members->first();
            
            // Pending mandatory savings
            Saving::create([
                'user_id' => $member->id,
                'cash_account_id' => $kasUmum->id,
                'savings_type' => 'mandatory',
                'amount' => 50000,
                'interest_percentage' => $interestRate,
                'final_amount' => Saving::calculateFinalAmount(50000, $interestRate),
                'transaction_date' => Carbon::now(),
                'status' => 'pending',
                'notes' => 'Simpanan Wajib bulan ini - menunggu persetujuan',
            ]);

            // Pending voluntary savings
            Saving::create([
                'user_id' => $member->id,
                'cash_account_id' => $kasUmum->id,
                'savings_type' => 'voluntary',
                'amount' => 300000,
                'interest_percentage' => $interestRate,
                'final_amount' => Saving::calculateFinalAmount(300000, $interestRate),
                'transaction_date' => Carbon::now(),
                'status' => 'pending',
                'notes' => 'Simpanan Sukarela - menunggu persetujuan',
            ]);
        }

        // Update cash account balance
        $totalApproved = Saving::where('cash_account_id', $kasUmum->id)
            ->where('status', 'approved')
            ->sum('amount');
        
        $kasUmum->current_balance = $kasUmum->opening_balance + $totalApproved;
        $kasUmum->save();
    }
}