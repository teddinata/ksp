<?php

namespace Database\Seeders;

use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\ChartOfAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class JournalSeeder extends Seeder
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

        // Get some accounts
        $kasUmum = ChartOfAccount::where('code', '1-101')->first();
        $simpananPokok = ChartOfAccount::where('code', '2-201')->first();
        $simpananWajib = ChartOfAccount::where('code', '2-202')->first();
        $piutangAnggota = ChartOfAccount::where('code', '1-103')->first();
        $pendapatanBunga = ChartOfAccount::where('code', '4-101')->first();
        $bebanOperasional = ChartOfAccount::where('code', '5-101')->first();

        if (!$kasUmum || !$simpananPokok) {
            $this->command->warn('Required accounts not found. Run ChartOfAccountSeeder first.');
            return;
        }

        $this->command->info('Creating journal entries...');

        // 1. Jurnal Umum - Setoran Modal Awal
        $journal1 = Journal::create([
            'journal_number' => Journal::generateJournalNumber('general'),
            'journal_type' => 'general',
            'description' => 'Setoran modal awal koperasi',
            'transaction_date' => Carbon::create(2024, 1, 1),
            'created_by' => $admin->id,
        ]);

        JournalDetail::create([
            'journal_id' => $journal1->id,
            'chart_of_account_id' => $kasUmum->id,
            'debit' => 50000000,
            'credit' => 0,
            'description' => 'Penerimaan modal awal',
        ]);

        JournalDetail::create([
            'journal_id' => $journal1->id,
            'chart_of_account_id' => ChartOfAccount::where('code', '3-101')->first()->id, // Modal Sendiri
            'debit' => 0,
            'credit' => 50000000,
            'description' => 'Modal awal koperasi',
        ]);

        $journal1->calculateTotals();
        $this->command->info("  ✓ {$journal1->journal_number} - Modal Awal: Rp 50,000,000");

        // 2. Jurnal Khusus - Simpanan Pokok
        $journal2 = Journal::create([
            'journal_number' => Journal::generateJournalNumber('special'),
            'journal_type' => 'special',
            'description' => 'Penerimaan simpanan pokok anggota',
            'transaction_date' => Carbon::create(2024, 2, 1),
            'created_by' => $admin->id,
        ]);

        JournalDetail::create([
            'journal_id' => $journal2->id,
            'chart_of_account_id' => $kasUmum->id,
            'debit' => 300000,
            'credit' => 0,
            'description' => 'Kas dari simpanan pokok',
        ]);

        JournalDetail::create([
            'journal_id' => $journal2->id,
            'chart_of_account_id' => $simpananPokok->id,
            'debit' => 0,
            'credit' => 300000,
            'description' => 'Simpanan pokok 3 anggota @ 100rb',
        ]);

        $journal2->calculateTotals();
        $this->command->info("  ✓ {$journal2->journal_number} - Simpanan Pokok: Rp 300,000");

        // 3. Jurnal Khusus - Simpanan Wajib
        $journal3 = Journal::create([
            'journal_number' => Journal::generateJournalNumber('special'),
            'journal_type' => 'special',
            'description' => 'Penerimaan simpanan wajib bulan Februari',
            'transaction_date' => Carbon::create(2024, 2, 28),
            'created_by' => $admin->id,
        ]);

        JournalDetail::create([
            'journal_id' => $journal3->id,
            'chart_of_account_id' => $kasUmum->id,
            'debit' => 900000,
            'credit' => 0,
        ]);

        JournalDetail::create([
            'journal_id' => $journal3->id,
            'chart_of_account_id' => $simpananWajib->id,
            'debit' => 0,
            'credit' => 900000,
        ]);

        $journal3->calculateTotals();
        $this->command->info("  ✓ {$journal3->journal_number} - Simpanan Wajib: Rp 900,000");

        // 4. Jurnal Khusus - Pencairan Pinjaman
        if ($piutangAnggota) {
            $journal4 = Journal::create([
                'journal_number' => Journal::generateJournalNumber('special'),
                'journal_type' => 'special',
                'description' => 'Pencairan pinjaman anggota',
                'transaction_date' => Carbon::create(2024, 3, 15),
                'created_by' => $admin->id,
            ]);

            JournalDetail::create([
                'journal_id' => $journal4->id,
                'chart_of_account_id' => $piutangAnggota->id,
                'debit' => 5000000,
                'credit' => 0,
                'description' => 'Piutang pinjaman anggota',
            ]);

            JournalDetail::create([
                'journal_id' => $journal4->id,
                'chart_of_account_id' => $kasUmum->id,
                'debit' => 0,
                'credit' => 5000000,
                'description' => 'Kas keluar untuk pinjaman',
            ]);

            $journal4->calculateTotals();
            $this->command->info("  ✓ {$journal4->journal_number} - Pencairan Pinjaman: Rp 5,000,000");
        }

        // 5. Jurnal Khusus - Pembayaran Angsuran
        if ($piutangAnggota && $pendapatanBunga) {
            $journal5 = Journal::create([
                'journal_number' => Journal::generateJournalNumber('special'),
                'journal_type' => 'special',
                'description' => 'Penerimaan angsuran pinjaman',
                'transaction_date' => Carbon::create(2024, 4, 15),
                'created_by' => $admin->id,
            ]);

            JournalDetail::create([
                'journal_id' => $journal5->id,
                'chart_of_account_id' => $kasUmum->id,
                'debit' => 444244,
                'credit' => 0,
                'description' => 'Penerimaan angsuran',
            ]);

            JournalDetail::create([
                'journal_id' => $journal5->id,
                'chart_of_account_id' => $piutangAnggota->id,
                'debit' => 0,
                'credit' => 416911,
                'description' => 'Pokok pinjaman',
            ]);

            JournalDetail::create([
                'journal_id' => $journal5->id,
                'chart_of_account_id' => $pendapatanBunga->id,
                'debit' => 0,
                'credit' => 27333,
                'description' => 'Pendapatan bunga',
            ]);

            $journal5->calculateTotals();
            $this->command->info("  ✓ {$journal5->journal_number} - Angsuran: Rp 444,244");
        }

        // 6. Jurnal Umum - Beban Operasional
        if ($bebanOperasional) {
            $journal6 = Journal::create([
                'journal_number' => Journal::generateJournalNumber('general'),
                'journal_type' => 'general',
                'description' => 'Pembayaran beban operasional bulan Maret',
                'transaction_date' => Carbon::create(2024, 3, 31),
                'created_by' => $admin->id,
            ]);

            JournalDetail::create([
                'journal_id' => $journal6->id,
                'chart_of_account_id' => $bebanOperasional->id,
                'debit' => 2500000,
                'credit' => 0,
                'description' => 'Beban operasional',
            ]);

            JournalDetail::create([
                'journal_id' => $journal6->id,
                'chart_of_account_id' => $kasUmum->id,
                'debit' => 0,
                'credit' => 2500000,
                'description' => 'Pembayaran kas',
            ]);

            $journal6->calculateTotals();
            $this->command->info("  ✓ {$journal6->journal_number} - Beban Operasional: Rp 2,500,000");
        }

        // 7. Jurnal Penyesuaian - Contoh
        $journal7 = Journal::create([
            'journal_number' => Journal::generateJournalNumber('adjusting'),
            'journal_type' => 'adjusting',
            'description' => 'Penyesuaian beban akrual',
            'transaction_date' => Carbon::create(2024, 4, 30),
            'created_by' => $admin->id,
        ]);

        $bebanAkrual = ChartOfAccount::where('code', 'LIKE', '5-%')->skip(1)->first();
        $hutangAkrual = ChartOfAccount::where('code', 'LIKE', '2-%')->skip(2)->first();

        if ($bebanAkrual && $hutangAkrual) {
            JournalDetail::create([
                'journal_id' => $journal7->id,
                'chart_of_account_id' => $bebanAkrual->id,
                'debit' => 500000,
                'credit' => 0,
                'description' => 'Beban yang masih harus dibayar',
            ]);

            JournalDetail::create([
                'journal_id' => $journal7->id,
                'chart_of_account_id' => $hutangAkrual->id,
                'debit' => 0,
                'credit' => 500000,
                'description' => 'Hutang beban',
            ]);

            $journal7->calculateTotals();
            $this->command->info("  ✓ {$journal7->journal_number} - Penyesuaian: Rp 500,000");
        }

        $this->command->info('Journal seeding completed!');
    }
}