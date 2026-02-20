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
        $admin = User::where('email', 'admin@ksu-ceria.test')->first();
        $members = User::where('role', 'anggota')->get();
        $kasUmum = CashAccount::where('code', 'KAS-I')->first();

        if (!$kasUmum || $members->isEmpty()) {
            $this->command->warn('Required data not found. Run UserSeeder and CashAccountSeeder first.');
            return;
        }

        // ✅ Ambil saving type IDs
        $savingTypes = \App\Models\SavingType::pluck('id', 'code');
        // $savingTypes = ['POKOK' => 1, 'WAJIB' => 2, 'SUKARELA' => 3, 'HARIRAYA' => 4]

        $typeMapping = [
            'principal' => $savingTypes['POKOK'] ?? null,
            'mandatory'  => $savingTypes['WAJIB'] ?? null,
            'voluntary'  => $savingTypes['SUKARELA'] ?? null,
            'holiday'    => $savingTypes['HARIRAYA'] ?? null,
        ];

        if (in_array(null, $typeMapping)) {
            $this->command->warn('SavingType data not found. Run SavingTypeSeeder first.');
            return;
        }

        $interestRate = 8.0;

        foreach ($members as $member) {
            // 1. Principal Savings
            $principalAmount = 100000;
            Saving::create([
                'user_id'            => $member->id,
                'cash_account_id'    => $kasUmum->id,
                'saving_type_id'     => $typeMapping['principal'], // ✅
                'savings_type'       => 'principal',
                'amount'             => $principalAmount,
                'interest_percentage'=> $interestRate,
                'final_amount'       => Saving::calculateFinalAmount($principalAmount, $interestRate),
                'transaction_date'   => Carbon::now()->subMonths(6),
                'status'             => 'approved',
                'notes'              => 'Simpanan Pokok saat bergabung',
                'approved_by'        => $admin?->id,
            ]);

            // 2. Mandatory Savings - 6 bulan
            $mandatoryAmount = 50000;
            for ($i = 6; $i >= 1; $i--) {
                Saving::create([
                    'user_id'            => $member->id,
                    'cash_account_id'    => $kasUmum->id,
                    'saving_type_id'     => $typeMapping['mandatory'], // ✅
                    'savings_type'       => 'mandatory',
                    'amount'             => $mandatoryAmount,
                    'interest_percentage'=> $interestRate,
                    'final_amount'       => Saving::calculateFinalAmount($mandatoryAmount, $interestRate),
                    'transaction_date'   => Carbon::now()->subMonths($i)->startOfMonth(),
                    'status'             => 'approved',
                    'notes'              => 'Simpanan Wajib bulan ' . Carbon::now()->subMonths($i)->format('F Y'),
                    'approved_by'        => $admin?->id,
                ]);
            }

            // 3. Voluntary Savings - random 2-3x
            $voluntaryTransactions = rand(2, 3);
            for ($i = 0; $i < $voluntaryTransactions; $i++) {
                $voluntaryAmount = rand(100, 500) * 1000;
                Saving::create([
                    'user_id'            => $member->id,
                    'cash_account_id'    => $kasUmum->id,
                    'saving_type_id'     => $typeMapping['voluntary'], // ✅
                    'savings_type'       => 'voluntary',
                    'amount'             => $voluntaryAmount,
                    'interest_percentage'=> $interestRate,
                    'final_amount'       => Saving::calculateFinalAmount($voluntaryAmount, $interestRate),
                    'transaction_date'   => Carbon::now()->subDays(rand(30, 150)),
                    'status'             => 'approved',
                    'notes'              => 'Simpanan Sukarela',
                    'approved_by'        => $admin?->id,
                ]);
            }

            // 4. Holiday Savings - 50% chance
            if (rand(0, 1)) {
                $holidayAmount = 200000;
                Saving::create([
                    'user_id'            => $member->id,
                    'cash_account_id'    => $kasUmum->id,
                    'saving_type_id'     => $typeMapping['holiday'], // ✅
                    'savings_type'       => 'holiday',
                    'amount'             => $holidayAmount,
                    'interest_percentage'=> $interestRate,
                    'final_amount'       => Saving::calculateFinalAmount($holidayAmount, $interestRate),
                    'transaction_date'   => Carbon::now()->subMonths(rand(1, 4)),
                    'status'             => 'approved',
                    'notes'              => 'Simpanan Hari Raya',
                    'approved_by'        => $admin?->id,
                ]);
            }
        }

        // Pending transactions
        if ($members->count() > 0) {
            $member = $members->first();

            Saving::create([
                'user_id'            => $member->id,
                'cash_account_id'    => $kasUmum->id,
                'saving_type_id'     => $typeMapping['mandatory'], // ✅
                'savings_type'       => 'mandatory',
                'amount'             => 50000,
                'interest_percentage'=> $interestRate,
                'final_amount'       => Saving::calculateFinalAmount(50000, $interestRate),
                'transaction_date'   => Carbon::now(),
                'status'             => 'pending',
                'notes'              => 'Simpanan Wajib bulan ini - menunggu persetujuan',
            ]);

            Saving::create([
                'user_id'            => $member->id,
                'cash_account_id'    => $kasUmum->id,
                'saving_type_id'     => $typeMapping['voluntary'], // ✅
                'savings_type'       => 'voluntary',
                'amount'             => 300000,
                'interest_percentage'=> $interestRate,
                'final_amount'       => Saving::calculateFinalAmount(300000, $interestRate),
                'transaction_date'   => Carbon::now(),
                'status'             => 'pending',
                'notes'              => 'Simpanan Sukarela - menunggu persetujuan',
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