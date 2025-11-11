<?php

namespace Database\Seeders;

use App\Models\CashAccount;
use App\Models\CashAccountManager;
use App\Models\InterestRate;
use App\Models\User;
use Illuminate\Database\Seeder;

class CashAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 5 Cash Accounts (Kas I-V)
        $cashAccounts = [
            [
                'code' => 'KAS-I',
                'name' => 'Kas Umum',
                'type' => 'I',
                'opening_balance' => 50000000, // 50 juta
                'current_balance' => 50000000,
                'description' => 'Kas untuk operasional umum koperasi',
                'is_active' => true,
            ],
            [
                'code' => 'KAS-II',
                'name' => 'Kas Sosial',
                'type' => 'II',
                'opening_balance' => 10000000, // 10 juta
                'current_balance' => 10000000,
                'description' => 'Kas untuk kegiatan sosial dan kesejahteraan anggota',
                'is_active' => true,
            ],
            [
                'code' => 'KAS-III',
                'name' => 'Kas Pengadaan',
                'type' => 'III',
                'opening_balance' => 30000000, // 30 juta
                'current_balance' => 30000000,
                'description' => 'Kas untuk pengadaan barang dan jasa',
                'is_active' => true,
            ],
            [
                'code' => 'KAS-IV',
                'name' => 'Kas Hadiah',
                'type' => 'IV',
                'opening_balance' => 5000000, // 5 juta
                'current_balance' => 5000000,
                'description' => 'Kas untuk pemberian hadiah kepada anggota',
                'is_active' => true,
            ],
            [
                'code' => 'KAS-V',
                'name' => 'Bank Mandiri',
                'type' => 'V',
                'opening_balance' => 100000000, // 100 juta
                'current_balance' => 100000000,
                'description' => 'Rekening Bank Mandiri Cabang Purwokerto',
                'is_active' => true,
            ],
        ];

        foreach ($cashAccounts as $accountData) {
            CashAccount::create($accountData);
        }

        // Get users for manager assignment
        $manager = User::where('email', 'manager@ksu-ceria.test')->first();
        
        if ($manager) {
            // Assign manager to Kas I, II, and III
            $kasI = CashAccount::where('code', 'KAS-I')->first();
            $kasII = CashAccount::where('code', 'KAS-II')->first();
            $kasIII = CashAccount::where('code', 'KAS-III')->first();

            if ($kasI) {
                CashAccountManager::create([
                    'manager_id' => $manager->id,
                    'cash_account_id' => $kasI->id,
                    'assigned_at' => now(),
                    'is_active' => true,
                ]);
            }

            if ($kasII) {
                CashAccountManager::create([
                    'manager_id' => $manager->id,
                    'cash_account_id' => $kasII->id,
                    'assigned_at' => now(),
                    'is_active' => true,
                ]);
            }

            if ($kasIII) {
                CashAccountManager::create([
                    'manager_id' => $manager->id,
                    'cash_account_id' => $kasIII->id,
                    'assigned_at' => now(),
                    'is_active' => true,
                ]);
            }
        }

        // Set interest rates for all cash accounts
        $admin = User::where('email', 'admin@ksu-ceria.test')->first();
        
        if ($admin) {
            $allCashAccounts = CashAccount::all();

            foreach ($allCashAccounts as $account) {
                // Savings interest rate: 8% per year
                InterestRate::create([
                    'cash_account_id' => $account->id,
                    'transaction_type' => 'savings',
                    'rate_percentage' => 8.00,
                    'effective_date' => now()->subMonths(3), // Started 3 months ago
                    'updated_by' => $admin->id,
                ]);

                // Loan interest rate: 12% per year
                InterestRate::create([
                    'cash_account_id' => $account->id,
                    'transaction_type' => 'loans',
                    'rate_percentage' => 12.00,
                    'effective_date' => now()->subMonths(3),
                    'updated_by' => $admin->id,
                ]);

                // Future rate change (example)
                // Savings rate will increase to 9% next month
                InterestRate::create([
                    'cash_account_id' => $account->id,
                    'transaction_type' => 'savings',
                    'rate_percentage' => 9.00,
                    'effective_date' => now()->addMonth(),
                    'updated_by' => $admin->id,
                ]);
            }
        }
    }
}