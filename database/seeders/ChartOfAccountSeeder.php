<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            // ========== ASSETS (ASET) ==========
            [
                'code' => '1-101',
                'name' => 'Kas Umum',
                'category' => 'assets',
                'account_type' => 'Cash',
                'is_debit' => true,
                'description' => 'Kas untuk operasional umum koperasi',
            ],
            [
                'code' => '1-102',
                'name' => 'Kas Sosial',
                'category' => 'assets',
                'account_type' => 'Cash',
                'is_debit' => true,
                'description' => 'Kas untuk kegiatan sosial',
            ],
            [
                'code' => '1-103',
                'name' => 'Kas Pengadaan',
                'category' => 'assets',
                'account_type' => 'Cash',
                'is_debit' => true,
                'description' => 'Kas untuk pengadaan barang/jasa',
            ],
            [
                'code' => '1-104',
                'name' => 'Kas Hadiah',
                'category' => 'assets',
                'account_type' => 'Cash',
                'is_debit' => true,
                'description' => 'Kas untuk hadiah anggota',
            ],
            [
                'code' => '1-105',
                'name' => 'Bank',
                'category' => 'assets',
                'account_type' => 'Bank',
                'is_debit' => true,
                'description' => 'Rekening bank koperasi',
            ],
            [
                'code' => '1-201',
                'name' => 'Piutang Anggota',
                'category' => 'assets',
                'account_type' => 'Receivables',
                'is_debit' => true,
                'description' => 'Piutang pinjaman kepada anggota',
            ],
            [
                'code' => '1-301',
                'name' => 'Persediaan',
                'category' => 'assets',
                'account_type' => 'Inventory',
                'is_debit' => true,
                'description' => 'Persediaan barang koperasi',
            ],
            [
                'code' => '1-401',
                'name' => 'Tanah',
                'category' => 'assets',
                'account_type' => 'Fixed Assets',
                'is_debit' => true,
                'description' => 'Tanah milik koperasi',
            ],
            [
                'code' => '1-402',
                'name' => 'Bangunan',
                'category' => 'assets',
                'account_type' => 'Fixed Assets',
                'is_debit' => true,
                'description' => 'Bangunan milik koperasi',
            ],
            [
                'code' => '1-403',
                'name' => 'Kendaraan',
                'category' => 'assets',
                'account_type' => 'Fixed Assets',
                'is_debit' => true,
                'description' => 'Kendaraan operasional koperasi',
            ],
            [
                'code' => '1-404',
                'name' => 'Peralatan',
                'category' => 'assets',
                'account_type' => 'Fixed Assets',
                'is_debit' => true,
                'description' => 'Peralatan kantor dan operasional',
            ],
            [
                'code' => '1-405',
                'name' => 'Akumulasi Penyusutan',
                'category' => 'assets',
                'account_type' => 'Accumulated Depreciation',
                'is_debit' => false,
                'description' => 'Akumulasi penyusutan aset tetap',
            ],

            // ========== LIABILITIES (KEWAJIBAN) ==========
            [
                'code' => '2-101',
                'name' => 'Hutang Usaha',
                'category' => 'liabilities',
                'account_type' => 'Payables',
                'is_debit' => false,
                'description' => 'Hutang kepada supplier',
            ],
            [
                'code' => '2-201',
                'name' => 'Simpanan Pokok Anggota',
                'category' => 'liabilities',
                'account_type' => 'Member Savings',
                'is_debit' => false,
                'description' => 'Simpanan pokok dari anggota',
            ],
            [
                'code' => '2-202',
                'name' => 'Simpanan Wajib Anggota',
                'category' => 'liabilities',
                'account_type' => 'Member Savings',
                'is_debit' => false,
                'description' => 'Simpanan wajib dari anggota',
            ],
            [
                'code' => '2-203',
                'name' => 'Simpanan Sukarela Anggota',
                'category' => 'liabilities',
                'account_type' => 'Member Savings',
                'is_debit' => false,
                'description' => 'Simpanan sukarela dari anggota',
            ],
            [
                'code' => '2-204',
                'name' => 'Simpanan Hari Raya',
                'category' => 'liabilities',
                'account_type' => 'Member Savings',
                'is_debit' => false,
                'description' => 'Simpanan khusus hari raya',
            ],

            // ========== EQUITY (MODAL) ==========
            [
                'code' => '3-101',
                'name' => 'Modal Sendiri',
                'category' => 'equity',
                'account_type' => 'Capital',
                'is_debit' => false,
                'description' => 'Modal dasar koperasi',
            ],
            [
                'code' => '3-201',
                'name' => 'Laba Ditahan',
                'category' => 'equity',
                'account_type' => 'Retained Earnings',
                'is_debit' => false,
                'description' => 'Laba yang tidak dibagikan',
            ],
            [
                'code' => '3-202',
                'name' => 'SHU Tahun Berjalan',
                'category' => 'equity',
                'account_type' => 'Current Year Earnings',
                'is_debit' => false,
                'description' => 'Sisa Hasil Usaha tahun berjalan',
            ],

            // ========== REVENUE (PENDAPATAN) ==========
            [
                'code' => '4-101',
                'name' => 'Pendapatan Bunga Pinjaman',
                'category' => 'revenue',
                'account_type' => 'Interest Income',
                'is_debit' => false,
                'description' => 'Pendapatan dari bunga pinjaman anggota',
            ],
            [
                'code' => '4-102',
                'name' => 'Pendapatan Administrasi',
                'category' => 'revenue',
                'account_type' => 'Administrative Income',
                'is_debit' => false,
                'description' => 'Pendapatan dari biaya administrasi',
            ],
            [
                'code' => '4-201',
                'name' => 'Pendapatan Lain-lain',
                'category' => 'revenue',
                'account_type' => 'Other Income',
                'is_debit' => false,
                'description' => 'Pendapatan di luar usaha utama',
            ],

            // ========== EXPENSES (BEBAN) ==========
            [
                'code' => '5-101',
                'name' => 'Beban Gaji',
                'category' => 'expenses',
                'account_type' => 'Salary Expense',
                'is_debit' => true,
                'description' => 'Beban gaji karyawan koperasi',
            ],
            [
                'code' => '5-102',
                'name' => 'Beban Operasional',
                'category' => 'expenses',
                'account_type' => 'Operating Expense',
                'is_debit' => true,
                'description' => 'Beban operasional harian',
            ],
            [
                'code' => '5-103',
                'name' => 'Beban Listrik dan Air',
                'category' => 'expenses',
                'account_type' => 'Utility Expense',
                'is_debit' => true,
                'description' => 'Beban utilitas kantor',
            ],
            [
                'code' => '5-104',
                'name' => 'Beban Penyusutan',
                'category' => 'expenses',
                'account_type' => 'Depreciation Expense',
                'is_debit' => true,
                'description' => 'Beban penyusutan aset tetap',
            ],
            [
                'code' => '5-105',
                'name' => 'Beban Hadiah',
                'category' => 'expenses',
                'account_type' => 'Gift Expense',
                'is_debit' => true,
                'description' => 'Beban pemberian hadiah kepada anggota',
            ],
            [
                'code' => '5-201',
                'name' => 'Beban Lain-lain',
                'category' => 'expenses',
                'account_type' => 'Other Expense',
                'is_debit' => true,
                'description' => 'Beban di luar operasional utama',
            ],
        ];

        foreach ($accounts as $account) {
            ChartOfAccount::create($account);
        }
    }
}