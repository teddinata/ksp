<?php

namespace Database\Seeders;

use App\Models\Saving;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            ChartOfAccountSeeder::class,
            CashAccountSeeder::class,
            AccountingPeriodSeeder::class,
            SavingSeeder::class,
            LoanSeeder::class,
            ServiceAllowanceSeeder::class,
            GiftSeeder::class,
        ]);
    }
}