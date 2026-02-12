<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\Log;

/**
 * AutoJournalService
 * 
 * Centralized service untuk membuat jurnal otomatis dari transaksi koperasi.
 * 
 * PENTING - COA LOOKUP:
 * Service ini mencari Chart of Account berdasarkan `code`.
 * Pastikan COA sudah di-seed dengan kode yang sesuai.
 * Jika COA tidak ditemukan, akan throw Exception agar transaksi tidak kehilangan jejak akuntansi.
 * 
 * STANDAR KODE AKUN (COA) KOPERASI:
 * ================================================
 * ASSETS (1-xxx):
 *   1-101  Kas Umum
 *   1-102  Kas Sosial
 *   1-103  Kas Pengadaan
 *   1-104  Kas Hadiah
 *   1-105  Bank
 *   1-201  Piutang Anggota (Pinjaman)
 * 
 * LIABILITIES (2-xxx):
 *   2-201  Simpanan Pokok Anggota
 *   2-202  Simpanan Wajib Anggota
 *   2-203  Simpanan Sukarela Anggota
 *   2-204  Simpanan Hari Raya
 * 
 * REVENUE (4-xxx):
 *   4-101  Pendapatan Bunga Pinjaman
 *   4-102  Pendapatan Administrasi
 * 
 * EXPENSES (5-xxx):
 *   5-101  Beban Gaji
 *   5-104  Beban Penyusutan
 * ================================================
 */
class AutoJournalService
{
    // ==================== COA CODE MAPPING ====================
    // Cash Account type → COA code
    private const CASH_ACCOUNT_COA_MAP = [
        'I' => '1-101', // Kas Umum
        'II' => '1-102', // Kas Sosial
        'III' => '1-103', // Kas Pengadaan
        'IV' => '1-104', // Kas Hadiah
        'V' => '1-105', // Bank
    ];

    // Saving type → COA code (liabilities)
    private const SAVING_TYPE_COA_MAP = [
        'principal' => '2-201', // Simpanan Pokok Anggota
        'mandatory' => '2-202', // Simpanan Wajib Anggota
        'voluntary' => '2-203', // Simpanan Sukarela Anggota
        'holiday' => '2-204', // Simpanan Hari Raya
    ];

    // Other COA codes
    private const COA_PIUTANG_PINJAMAN = '1-201';
    private const COA_PENDAPATAN_BUNGA = '4-101';
    private const COA_PENDAPATAN_LAIN = '4-201';
    private const COA_BEBAN_BUNGA_SIMPANAN = '5-101';

    // ==================== HELPER: COA LOOKUP ====================

    /**
     * Get COA by code, throw if not found.
     */
    private static function getCoa(string $code): ChartOfAccount
    {
        $coa = ChartOfAccount::where('code', $code)->where('is_active', true)->first();

        if (!$coa) {
            throw new \Exception(
                "Chart of Account dengan kode '{$code}' tidak ditemukan atau tidak aktif. " .
                "Pastikan COA sudah di-seed dengan benar."
                );
        }

        return $coa;
    }

    /**
     * Get COA ID for a cash account type.
     */
    private static function getCashAccountCoaId(string $cashAccountType): int
    {
        $code = self::CASH_ACCOUNT_COA_MAP[$cashAccountType] ?? null;

        if (!$code) {
            throw new \Exception("Tipe kas '{$cashAccountType}' tidak memiliki mapping COA.");
        }

        return self::getCoa($code)->id;
    }

    /**
     * Get COA ID for a saving type.
     */
    private static function getSavingCoaId(string $savingType): int
    {
        $code = self::SAVING_TYPE_COA_MAP[$savingType] ?? null;

        if (!$code) {
            throw new \Exception("Jenis simpanan '{$savingType}' tidak memiliki mapping COA.");
        }

        return self::getCoa($code)->id;
    }

    // ==================== HELPER: CREATE JOURNAL ====================

    /**
     * Create journal with details.
     * 
     * @param array $journalData  Journal header data
     * @param array $details      Array of [chart_of_account_id, debit, credit, description]
     * @return Journal
     */
    private static function createJournal(array $journalData, array $details): Journal
    {
        $journal = Journal::create([
            'journal_number' => Journal::generateJournalNumber($journalData['journal_type'] ?? 'special'),
            'journal_type' => $journalData['journal_type'] ?? 'special',
            'description' => $journalData['description'],
            'transaction_date' => $journalData['transaction_date'],
            'created_by' => $journalData['created_by'],
            'is_auto_generated' => true,
            'is_editable' => false,
            'source_module' => $journalData['source_module'],
            'reference_type' => $journalData['reference_type'] ?? null,
            'reference_id' => $journalData['reference_id'] ?? null,
        ]);

        foreach ($details as $detail) {
            JournalDetail::create([
                'journal_id' => $journal->id,
                'chart_of_account_id' => $detail['chart_of_account_id'],
                'debit' => $detail['debit'] ?? 0,
                'credit' => $detail['credit'] ?? 0,
                'description' => $detail['description'] ?? null,
            ]);
        }

        $journal->calculateTotals();

        return $journal;
    }

    // ==================== 1. SAVING JOURNAL ====================

    /**
     * Jurnal saat simpanan di-approve/create.
     * 
     * Dr. Kas (sesuai cash account)      xxx
     *   Cr. Simpanan Anggota (sesuai tipe)  xxx
     * 
     * @param \App\Models\Saving $saving
     * @param int $approvedBy  User ID yang approve
     * @return Journal
     */
    public static function savingApproved(\App\Models\Saving $saving, int $approvedBy): Journal
    {
        $saving->load(['cashAccount', 'user']);

        $cashCoaId = self::getCashAccountCoaId($saving->cashAccount->type);
        $savingCoaId = self::getSavingCoaId($saving->savings_type);
        $amount = $saving->amount;
        $memberName = $saving->user->full_name;
        $typeName = $saving->type_name;

        return self::createJournal(
        [
            'description' => "Simpanan {$typeName} - {$memberName}",
            'transaction_date' => $saving->transaction_date,
            'created_by' => $approvedBy,
            'source_module' => 'savings',
            'reference_type' => 'App\\Models\\Saving',
            'reference_id' => $saving->id,
        ],
        [
            [
                'chart_of_account_id' => $cashCoaId,
                'debit' => $amount,
                'credit' => 0,
                'description' => "Kas masuk: {$typeName} dari {$memberName}",
            ],
            [
                'chart_of_account_id' => $savingCoaId,
                'debit' => 0,
                'credit' => $amount,
                'description' => "{$typeName} {$memberName}",
            ],
        ]
        );
    }

    // ==================== 2. LOAN DISBURSEMENT JOURNAL ====================

    /**
     * Jurnal saat pinjaman dicairkan (approved & disbursed).
     * 
     * Dr. Piutang Pinjaman Anggota    xxx
     *   Cr. Kas (sesuai cash account)    xxx
     * 
     * @param \App\Models\Loan $loan
     * @param int $approvedBy
     * @return Journal
     */
    public static function loanDisbursed(\App\Models\Loan $loan, int $approvedBy): Journal
    {
        $loan->load(['cashAccount', 'user']);

        $piutangCoaId = self::getCoa(self::COA_PIUTANG_PINJAMAN)->id;
        $cashCoaId = self::getCashAccountCoaId($loan->cashAccount->type);
        $amount = $loan->principal_amount;
        $memberName = $loan->user->full_name;

        return self::createJournal(
        [
            'description' => "Pencairan Pinjaman {$loan->loan_number} - {$memberName}",
            'transaction_date' => $loan->disbursement_date ?? now(),
            'created_by' => $approvedBy,
            'source_module' => 'loans',
            'reference_type' => 'App\\Models\\Loan',
            'reference_id' => $loan->id,
        ],
        [
            [
                'chart_of_account_id' => $piutangCoaId,
                'debit' => $amount,
                'credit' => 0,
                'description' => "Piutang pinjaman {$loan->loan_number} - {$memberName}",
            ],
            [
                'chart_of_account_id' => $cashCoaId,
                'debit' => 0,
                'credit' => $amount,
                'description' => "Kas keluar: pencairan pinjaman {$memberName}",
            ],
        ]
        );
    }

    // ==================== 3. INSTALLMENT PAYMENT JOURNAL ====================

    /**
     * Jurnal saat cicilan dibayar.
     * 
     * Dr. Kas (sesuai cash account pinjaman)   xxx (total_amount)
     *   Cr. Piutang Pinjaman Anggota              xxx (principal_amount)
     *   Cr. Pendapatan Bunga Pinjaman             xxx (interest_amount)
     * 
     * @param \App\Models\Installment $installment
     * @param int $confirmedBy
     * @param string $paymentMethod  'cash', 'salary', 'service_allowance', etc
     * @return Journal
     */
    public static function installmentPaid(
        \App\Models\Installment $installment,
        int $confirmedBy,
        string $paymentMethod = 'cash'
        ): Journal
    {
        $installment->load(['loan.cashAccount', 'loan.user']);

        $loan = $installment->loan;
        $cashCoaId = self::getCashAccountCoaId($loan->cashAccount->type);
        $piutangCoaId = self::getCoa(self::COA_PIUTANG_PINJAMAN)->id;
        $bungaCoaId = self::getCoa(self::COA_PENDAPATAN_BUNGA)->id;
        $memberName = $loan->user->full_name;
        $methodLabel = match ($paymentMethod) {
                'salary' => 'potong gaji',
                'service_allowance' => 'jasa pelayanan',
                default => 'manual/kas',
            };

        $details = [
            [
                'chart_of_account_id' => $cashCoaId,
                'debit' => $installment->total_amount,
                'credit' => 0,
                'description' => "Kas masuk: cicilan #{$installment->installment_number} {$loan->loan_number} ({$methodLabel})",
            ],
            [
                'chart_of_account_id' => $piutangCoaId,
                'debit' => 0,
                'credit' => $installment->principal_amount,
                'description' => "Pokok cicilan #{$installment->installment_number} - {$memberName}",
            ],
        ];

        // Hanya tambah pendapatan bunga jika interest > 0
        if ($installment->interest_amount > 0) {
            $details[] = [
                'chart_of_account_id' => $bungaCoaId,
                'debit' => 0,
                'credit' => $installment->interest_amount,
                'description' => "Bunga cicilan #{$installment->installment_number} - {$memberName}",
            ];
        }

        return self::createJournal(
        [
            'description' => "Pembayaran Cicilan #{$installment->installment_number} {$loan->loan_number} - {$memberName} ({$methodLabel})",
            'transaction_date' => now(),
            'created_by' => $confirmedBy,
            'source_module' => 'installments',
            'reference_type' => 'App\\Models\\Installment',
            'reference_id' => $installment->id,
        ],
            $details
        );
    }

    // ==================== 4. CASH TRANSFER JOURNAL ====================

    /**
     * Jurnal saat transfer kas di-approve.
     * 
     * Dr. Kas Tujuan (to)      xxx
     *   Cr. Kas Sumber (from)    xxx
     * 
     * @param \App\Models\CashTransfer $transfer
     * @param int $approvedBy
     * @return Journal
     */
    public static function cashTransferApproved(\App\Models\CashTransfer $transfer, int $approvedBy): Journal
    {
        $transfer->load(['fromCashAccount', 'toCashAccount']);

        $fromCoaId = self::getCashAccountCoaId($transfer->fromCashAccount->type);
        $toCoaId = self::getCashAccountCoaId($transfer->toCashAccount->type);

        return self::createJournal(
        [
            'description' => "Transfer Kas: {$transfer->fromCashAccount->name} → {$transfer->toCashAccount->name} - {$transfer->purpose}",
            'transaction_date' => $transfer->transfer_date,
            'created_by' => $approvedBy,
            'source_module' => 'cash_transfers',
            'reference_type' => 'App\\Models\\CashTransfer',
            'reference_id' => $transfer->id,
        ],
        [
            [
                'chart_of_account_id' => $toCoaId,
                'debit' => $transfer->amount,
                'credit' => 0,
                'description' => "Kas masuk: transfer dari {$transfer->fromCashAccount->name}",
            ],
            [
                'chart_of_account_id' => $fromCoaId,
                'debit' => 0,
                'credit' => $transfer->amount,
                'description' => "Kas keluar: transfer ke {$transfer->toCashAccount->name}",
            ],
        ]
        );
    }

    // ==================== 5. SALARY DEDUCTION JOURNAL ====================

    /**
     * Jurnal saat potongan gaji diproses.
     * 
     * Jika ada loan_deduction:
     *   Dr. Kas Umum                          xxx (total loan deduction)
     *     Cr. Piutang Pinjaman Anggota           xxx (principal portion)
     *     Cr. Pendapatan Bunga Pinjaman          xxx (interest portion)
     * 
     * Jika ada savings_deduction:
     *   Dr. Kas Umum                          xxx
     *     Cr. Simpanan Wajib Anggota             xxx
     * 
     * @param \App\Models\SalaryDeduction $deduction
     * @param int $processedBy
     * @param float $principalPortion  Porsi pokok dari loan_deduction
     * @param float $interestPortion   Porsi bunga dari loan_deduction
     * @return Journal|null  Null jika tidak ada deduction
     */
    public static function salaryDeductionProcessed(
        \App\Models\SalaryDeduction $deduction,
        int $processedBy,
        float $principalPortion = 0,
        float $interestPortion = 0
        ): ?Journal
    {
        $deduction->load('user');

        $totalDeductions = $deduction->total_deductions;
        if ($totalDeductions <= 0) {
            return null; // Tidak perlu jurnal jika tidak ada potongan
        }

        $memberName = $deduction->user->full_name;
        $kasCoaId = self::getCoa(self::CASH_ACCOUNT_COA_MAP['I'])->id; // Default Kas Umum
        $details = [];

        // Debit total ke kas
        $details[] = [
            'chart_of_account_id' => $kasCoaId,
            'debit' => $totalDeductions,
            'credit' => 0,
            'description' => "Potongan gaji {$memberName} periode {$deduction->period_display}",
        ];

        // Credit: Loan deduction → split pokok & bunga
        if ($deduction->loan_deduction > 0) {
            $piutangCoaId = self::getCoa(self::COA_PIUTANG_PINJAMAN)->id;

            // Jika porsi principal/interest tidak dipisah, anggap semua pokok
            if ($principalPortion <= 0 && $interestPortion <= 0) {
                $principalPortion = $deduction->loan_deduction;
            }

            if ($principalPortion > 0) {
                $details[] = [
                    'chart_of_account_id' => $piutangCoaId,
                    'debit' => 0,
                    'credit' => $principalPortion,
                    'description' => "Pokok pinjaman via potong gaji - {$memberName}",
                ];
            }

            if ($interestPortion > 0) {
                $bungaCoaId = self::getCoa(self::COA_PENDAPATAN_BUNGA)->id;
                $details[] = [
                    'chart_of_account_id' => $bungaCoaId,
                    'debit' => 0,
                    'credit' => $interestPortion,
                    'description' => "Bunga pinjaman via potong gaji - {$memberName}",
                ];
            }
        }

        // Credit: Savings deduction → Simpanan Wajib
        if ($deduction->savings_deduction > 0) {
            $simpananWajibCoaId = self::getCoa(self::SAVING_TYPE_COA_MAP['mandatory'])->id;
            $details[] = [
                'chart_of_account_id' => $simpananWajibCoaId,
                'debit' => 0,
                'credit' => $deduction->savings_deduction,
                'description' => "Simpanan wajib via potong gaji - {$memberName}",
            ];
        }

        // Credit: Other deductions → Pendapatan Lain-lain
        if ($deduction->other_deductions > 0) {
            $pendapatanLainCoaId = self::getCoa(self::COA_PENDAPATAN_LAIN)->id;
            $details[] = [
                'chart_of_account_id' => $pendapatanLainCoaId,
                'debit' => 0,
                'credit' => $deduction->other_deductions,
                'description' => "Potongan lain-lain via gaji - {$memberName}",
            ];
        }

        return self::createJournal(
        [
            'description' => "Potongan Gaji - {$memberName} ({$deduction->period_display})",
            'transaction_date' => $deduction->deduction_date ?? now(),
            'created_by' => $processedBy,
            'source_module' => 'salary_deductions',
            'reference_type' => 'App\\Models\\SalaryDeduction',
            'reference_id' => $deduction->id,
        ],
            $details
        );
    }

    // ==================== 6. SERVICE ALLOWANCE JOURNAL ====================

    /**
     * Jurnal saat jasa pelayanan diproses (jika ada installment_paid).
     * 
     * Dr. Kas Umum                              xxx (installment_paid)
     *   Cr. Piutang Pinjaman Anggota               xxx
     *   Cr. Pendapatan Bunga Pinjaman              xxx
     * 
     * @param \App\Models\ServiceAllowance $allowance
     * @param int $processedBy
     * @param float $principalPortion
     * @param float $interestPortion
     * @return Journal|null
     */
    public static function serviceAllowanceProcessed(
        \App\Models\ServiceAllowance $allowance,
        int $processedBy,
        float $principalPortion = 0,
        float $interestPortion = 0
        ): ?Journal
    {
        $allowance->load('user');

        if ($allowance->installment_paid <= 0) {
            return null; // Tidak ada cicilan yang dipotong
        }

        $memberName = $allowance->user->full_name;
        $kasCoaId = self::getCoa(self::CASH_ACCOUNT_COA_MAP['I'])->id;
        $details = [];

        // Debit ke kas
        $details[] = [
            'chart_of_account_id' => $kasCoaId,
            'debit' => $allowance->installment_paid,
            'credit' => 0,
            'description' => "Jasa pelayanan dipotong untuk cicilan - {$memberName}",
        ];

        // Credit: split pokok dan bunga
        if ($principalPortion <= 0 && $interestPortion <= 0) {
            $principalPortion = $allowance->installment_paid;
        }

        if ($principalPortion > 0) {
            $piutangCoaId = self::getCoa(self::COA_PIUTANG_PINJAMAN)->id;
            $details[] = [
                'chart_of_account_id' => $piutangCoaId,
                'debit' => 0,
                'credit' => $principalPortion,
                'description' => "Pokok pinjaman via jasa pelayanan - {$memberName}",
            ];
        }

        if ($interestPortion > 0) {
            $bungaCoaId = self::getCoa(self::COA_PENDAPATAN_BUNGA)->id;
            $details[] = [
                'chart_of_account_id' => $bungaCoaId,
                'debit' => 0,
                'credit' => $interestPortion,
                'description' => "Bunga pinjaman via jasa pelayanan - {$memberName}",
            ];
        }

        return self::createJournal(
        [
            'description' => "Potongan Jasa Pelayanan untuk Cicilan - {$memberName} ({$allowance->period_display})",
            'transaction_date' => $allowance->payment_date ?? now(),
            'created_by' => $processedBy,
            'source_module' => 'service_allowances',
            'reference_type' => 'App\\Models\\ServiceAllowance',
            'reference_id' => $allowance->id,
        ],
            $details
        );
    }

    // ==================== 7. EARLY SETTLEMENT JOURNAL ====================

    /**
     * Jurnal saat pelunasan dipercepat.
     * 
     * Dr. Kas (sesuai cash account pinjaman)   xxx (settlement_amount)
     *   Cr. Piutang Pinjaman Anggota              xxx (settlement_amount)
     * 
     * @param \App\Models\Loan $loan
     * @param int $settledBy
     * @return Journal
     */
    public static function loanEarlySettlement(\App\Models\Loan $loan, int $settledBy): Journal
    {
        $loan->load(['cashAccount', 'user']);

        $cashCoaId = self::getCashAccountCoaId($loan->cashAccount->type);
        $piutangCoaId = self::getCoa(self::COA_PIUTANG_PINJAMAN)->id;
        $memberName = $loan->user->full_name;
        $amount = $loan->settlement_amount;

        return self::createJournal(
        [
            'description' => "Pelunasan Dipercepat {$loan->loan_number} - {$memberName}",
            'transaction_date' => $loan->settlement_date ?? now(),
            'created_by' => $settledBy,
            'source_module' => 'loans',
            'reference_type' => 'App\\Models\\Loan',
            'reference_id' => $loan->id,
        ],
        [
            [
                'chart_of_account_id' => $cashCoaId,
                'debit' => $amount,
                'credit' => 0,
                'description' => "Kas masuk: pelunasan dipercepat {$loan->loan_number}",
            ],
            [
                'chart_of_account_id' => $piutangCoaId,
                'debit' => 0,
                'credit' => $amount,
                'description' => "Pelunasan piutang {$loan->loan_number} - {$memberName}",
            ],
        ]
        );
    }
}