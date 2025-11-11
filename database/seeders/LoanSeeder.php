<?php

namespace Database\Seeders;

use App\Models\Loan;
use App\Models\User;
use App\Models\CashAccount;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class LoanSeeder extends Seeder
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

        // Interest rate for loans (12%)
        $interestRate = 12.0;

        // Create active loans for members
        foreach ($members->take(2) as $index => $member) {
            $principal = ($index + 1) * 5000000; // 5jt, 10jt
            $tenure = 12; // 12 months
            $installment = Loan::calculateInstallment($principal, $interestRate, $tenure);

            $loan = Loan::create([
                'user_id' => $member->id,
                'cash_account_id' => $kasUmum->id,
                'loan_number' => Loan::generateLoanNumber(),
                'principal_amount' => $principal,
                'interest_percentage' => $interestRate,
                'tenure_months' => $tenure,
                'installment_amount' => $installment,
                'status' => 'active',
                'application_date' => Carbon::now()->subMonths(4),
                'approval_date' => Carbon::now()->subMonths(4)->addDays(2),
                'disbursement_date' => Carbon::now()->subMonths(4)->addDays(3),
                'loan_purpose' => 'Renovasi rumah dan modal usaha',
                'approved_by' => $admin ? $admin->id : null,
            ]);

            // Create installments
            $loan->createInstallmentSchedule();

            // Mark first 3 installments as paid
            $installments = $loan->installments()->orderBy('installment_number')->get();
            foreach ($installments->take(3) as $installment) {
                $installment->markAsPaid('service_allowance', $admin ? $admin->id : null, 'Pembayaran otomatis dari jasa pelayanan');
            }

            // Update overdue status
            $loan->updateOverdueStatus();
        }

        // Create paid off loan (completed)
        if ($members->count() > 2) {
            $member = $members->skip(2)->first();
            $principal = 3000000; // 3jt
            $tenure = 6; // 6 months
            $installment = Loan::calculateInstallment($principal, $interestRate, $tenure);

            $loan = Loan::create([
                'user_id' => $member->id,
                'cash_account_id' => $kasUmum->id,
                'loan_number' => 'LN-20240701-0001',
                'principal_amount' => $principal,
                'interest_percentage' => $interestRate,
                'tenure_months' => $tenure,
                'installment_amount' => $installment,
                'status' => 'paid_off',
                'application_date' => Carbon::now()->subMonths(8),
                'approval_date' => Carbon::now()->subMonths(8)->addDays(1),
                'disbursement_date' => Carbon::now()->subMonths(8)->addDays(2),
                'loan_purpose' => 'Biaya pendidikan anak',
                'approved_by' => $admin ? $admin->id : null,
            ]);

            // Create installments
            $loan->createInstallmentSchedule();

            // Mark all as paid
            foreach ($loan->installments as $installment) {
                $installment->markAsPaid(
                    'service_allowance',
                    $admin ? $admin->id : null,
                    'Pembayaran otomatis - Lunas'
                );
            }
        }

        // Create pending loan application
        if ($members->count() > 0) {
            $member = $members->first();
            $principal = 8000000; // 8jt
            $tenure = 24; // 24 months
            $installment = Loan::calculateInstallment($principal, $interestRate, $tenure);

            Loan::create([
                'user_id' => $member->id,
                'cash_account_id' => $kasUmum->id,
                'loan_number' => Loan::generateLoanNumber(),
                'principal_amount' => $principal,
                'interest_percentage' => $interestRate,
                'tenure_months' => $tenure,
                'installment_amount' => $installment,
                'status' => 'pending',
                'application_date' => Carbon::now()->subDays(2),
                'loan_purpose' => 'Modal usaha warung sembako',
            ]);
        }

        // Create rejected loan
        if ($members->count() > 1) {
            $member = $members->skip(1)->first();
            $principal = 15000000; // 15jt
            $tenure = 36; // 36 months
            $installment = Loan::calculateInstallment($principal, $interestRate, $tenure);

            Loan::create([
                'user_id' => $member->id,
                'cash_account_id' => $kasUmum->id,
                'loan_number' => 'LN-20241215-0003',
                'principal_amount' => $principal,
                'interest_percentage' => $interestRate,
                'tenure_months' => $tenure,
                'installment_amount' => $installment,
                'status' => 'rejected',
                'application_date' => Carbon::now()->subMonths(1),
                'loan_purpose' => 'Pembelian kendaraan',
                'rejection_reason' => 'Tidak memenuhi syarat kelengkapan dokumen dan riwayat kredit tidak memadai',
                'approved_by' => $admin ? $admin->id : null,
            ]);
        }

        // Update cash account balance (subtract approved loans)
        $totalApproved = Loan::where('cash_account_id', $kasUmum->id)
            ->whereIn('status', ['disbursed', 'active'])
            ->sum('principal_amount');
        
        $kasUmum->current_balance -= $totalApproved;
        $kasUmum->save();
    }
}