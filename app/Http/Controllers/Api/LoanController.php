<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanRequest;
use App\Http\Requests\ApproveLoanRequest;
use App\Models\Loan;
use App\Models\User;
use App\Models\CashAccount;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\AutoJournalService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    use ApiResponse;

    public function checkEligibility(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'cash_account_id' => 'nullable|exists:cash_accounts,id',
            ]);

            $user = User::findOrFail($validated['user_id']);

            if (!$user->isMember()) {
                return $this->errorResponse('Hanya member yang dapat mengajukan pinjaman', 400);
            }

            if ($user->status !== 'active') {
                return $this->errorResponse('Member tidak aktif', 400);
            }

            $response = [
                'user' => $user->only(['id', 'full_name', 'employee_id', 'email']),
                'loan_summary' => $user->getLoanSummary(),
                'available_cash_accounts' => $user->getAvailableCashAccountsForLoan(),
            ];

            if (isset($validated['cash_account_id'])) {
                $cashAccountId = $validated['cash_account_id'];
                $check = $user->canApplyForLoan($cashAccountId);
                $cashAccount = CashAccount::find($cashAccountId);

                $response['check_result'] = [
                    'cash_account' => $cashAccount ? [
                        'id' => $cashAccount->id,
                        'code' => $cashAccount->code,
                        'name' => $cashAccount->name,
                        'type' => $cashAccount->type,
                    ] : null,
                    'can_apply' => $check['can_apply'],
                    'reason' => $check['reason'],
                ];
            }

            return $this->successResponse($response, 'Eligibility checked successfully');

        } catch (\Exception $e) {
            return $this->errorResponse('Gagal memeriksa kelayakan: ' . $e->getMessage(), 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Loan::with(['user:id,full_name,employee_id', 'cashAccount:id,code,name', 'approvedBy:id,full_name']);

            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            if ($request->has('user_id') && ($user->isAdmin() || $user->isManager())) {
                $query->byUser($request->user_id);
            }

            if ($request->has('cash_account_id')) {
                $query->byCashAccount($request->cash_account_id);
            }

            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            if ($request->has('search') && ($user->isAdmin() || $user->isManager())) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('loan_number', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q2) use ($search) {
                            $q2->where('full_name', 'like', "%{$search}%")
                                ->orWhere('employee_id', 'like', "%{$search}%");
                        });
                });
            }

            $sortBy = $request->get('sort_by', 'application_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->get('per_page', 15);

            if ($request->has('all') && $request->boolean('all')) {
                $loans = $query->get();
                return $this->successResponse($loans, 'Loans retrieved successfully');
            } else {
                $loans = $query->paginate($perPage);
                return $this->paginatedResponse($loans, 'Loans retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve loans: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ✅ FIXED: currentLoanRate() bug
     */
    public function store(LoanRequest $request): JsonResponse
    {
        try {
            $cashAccount = CashAccount::findOrFail($request->cash_account_id);
            
            // ✅ FIX: Use ->first() to get actual InterestRate object
            $interestRate = $cashAccount->currentLoanRate()->first();
            $interestPercentage = $interestRate ? $interestRate->rate_percentage : 12.0;

            $installmentAmount = Loan::calculateInstallment(
                $request->principal_amount,
                $interestPercentage,
                $request->tenure_months
            );

            $loanNumber = Loan::generateLoanNumber();

            // Create loan
            $loan = Loan::create([
                'user_id'                            => $request->user_id,
                'cash_account_id'                    => $request->cash_account_id,
                'loan_number'                        => $loanNumber,
                'principal_amount'                   => $request->principal_amount,
                'interest_percentage'                => $interestPercentage,
                'tenure_months'                      => $request->tenure_months,
                'installment_amount'                 => $installmentAmount,
                'status'                             => 'pending',
                'application_date'                   => $request->application_date,
                'loan_purpose'                       => $request->loan_purpose,
                'document_path'                      => $request->document_path,

                // ✅ Tambahkan ini
                'deduction_method'                          => $request->deduction_method ?? 'none',
                'salary_deduction_percentage'               => $request->salary_deduction_percentage ?? 0,
                'service_allowance_deduction_percentage'    => $request->service_allowance_deduction_percentage ?? 0,
            ]);

            $loan->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name']);

            return $this->successResponse($loan, 'Pengajuan pinjaman berhasil. Menunggu persetujuan admin.', 201);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create loan application: ' . $e->getMessage(), 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Loan::with(['user:id,full_name,employee_id,email', 'cashAccount:id,code,name', 'approvedBy:id,full_name', 'installments']);

            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            $loan = $query->findOrFail($id);

            return $this->successResponse($loan, 'Loan retrieved successfully');

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve loan: ' . $e->getMessage(), 500);
        }
    }

    public function update(LoanRequest $request, int $id): JsonResponse
    {
        try {
            $loan = Loan::findOrFail($id);

            if (!$loan->isPending()) {
                return $this->errorResponse('Cannot update loan that is already ' . $loan->status, 400);
            }

            $cashAccount = CashAccount::findOrFail($request->cash_account_id);
            $interestRate = $cashAccount->currentLoanRate()->first();
            $interestPercentage = $interestRate ? $interestRate->rate_percentage : 12.0;

            $installmentAmount = Loan::calculateInstallment(
                $request->principal_amount,
                $interestPercentage,
                $request->tenure_months
            );

            $loan->update([
                'user_id'                            => $request->user_id,
                'cash_account_id'                    => $request->cash_account_id,
                'principal_amount'                   => $request->principal_amount,
                'interest_percentage'                => $interestPercentage,
                'tenure_months'                      => $request->tenure_months,
                'installment_amount'                 => $installmentAmount,
                'application_date'                   => $request->application_date,
                'loan_purpose'                       => $request->loan_purpose,
                'document_path'                      => $request->document_path,

                // ✅ Tambahkan ini
                'deduction_method'                          => $request->deduction_method ?? $loan->deduction_method,
                'salary_deduction_percentage'               => $request->salary_deduction_percentage ?? $loan->salary_deduction_percentage,
                'service_allowance_deduction_percentage'    => $request->service_allowance_deduction_percentage ?? $loan->service_allowance_deduction_percentage,
            ]);

            $loan->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name']);

            return $this->successResponse($loan, 'Loan application updated successfully');

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update loan: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $loan = Loan::findOrFail($id);

            if (!$loan->isPending()) {
                return $this->errorResponse('Cannot delete loan that is ' . $loan->status, 400);
            }

            $loan->delete();

            return $this->successResponse(null, 'Loan application deleted successfully');

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to delete loan: ' . $e->getMessage(), 500);
        }
    }

    public function approve(ApproveLoanRequest $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $loan = Loan::findOrFail($id);

            if (!$loan->isPending()) {
                return $this->errorResponse('Loan is already ' . $loan->status, 400);
            }

            DB::beginTransaction();

            if ($request->status === 'approved') {
                $loan->update([
                    'status' => 'active',
                    'approval_date' => now(),
                    'approved_by' => $user->id,
                    'disbursement_date' => $request->disbursement_date ?? now(),
                ]);

                $loan->createInstallmentSchedule();

                $cashAccount = CashAccount::find($loan->cash_account_id);
                if ($cashAccount) {
                    $cashAccount->updateBalance($loan->principal_amount, 'subtract');
                }

                AutoJournalService::loanDisbursed($loan, $user->id);
            } else {
                $loan->update([
                    'status' => 'rejected',
                    'rejection_reason' => $request->rejection_reason,
                    'approved_by' => $user->id,
                ]);
            }

            DB::commit();

            $loan->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name', 'approvedBy:id,full_name']);

            $message = $request->status === 'approved' ? 'Pinjaman disetujui dan langsung dicairkan' : 'Pinjaman ditolak';

            return $this->successResponse($loan, $message);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to process approval: ' . $e->getMessage(), 500);
        }
    }

    public function getSummary(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $userId = $request->get('user_id', $user->id);

            if ($user->isMember() && $userId != $user->id) {
                return $this->errorResponse('Access denied', 403);
            }

            $targetUser = User::findOrFail($userId);
            $activeLoans = Loan::where('user_id', $userId)->whereIn('status', ['disbursed', 'active'])->get();

            $summary = [
                'user' => $targetUser->only(['id', 'full_name', 'employee_id']),
                'total_active_loans' => $activeLoans->count(),
                'total_principal_borrowed' => $activeLoans->sum('principal_amount'),
                'total_remaining_principal' => $activeLoans->sum(function ($loan) {
                    return $loan->remaining_principal;
                }),
                'total_monthly_installment' => $activeLoans->sum('installment_amount'),
                'loan_history' => [
                    'completed' => Loan::where('user_id', $userId)->where('status', 'paid_off')->count(),
                    'rejected' => Loan::where('user_id', $userId)->where('status', 'rejected')->count(),
                ],
            ];

            return $this->successResponse($summary, 'Loan summary retrieved successfully');

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve summary: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ✅ FIXED: currentLoanRate() bug
     */
    public function simulate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'principal_amount' => 'required|numeric|min:100000',
                'tenure_months' => 'required|integer|min:6|max:60',
                'cash_account_id' => 'required|exists:cash_accounts,id',
            ]);

            $cashAccount = CashAccount::findOrFail($request->cash_account_id);
            
            // ✅ FIX: Use ->first()
            $interestRate = $cashAccount->currentLoanRate()->first();
            $interestPercentage = $interestRate ? $interestRate->rate_percentage : 12.0;

            $installmentAmount = Loan::calculateInstallment(
                $request->principal_amount,
                $interestPercentage,
                $request->tenure_months
            );

            $totalAmount = $installmentAmount * $request->tenure_months;
            $totalInterest = $totalAmount - $request->principal_amount;

            $simulation = [
                'principal_amount' => $request->principal_amount,
                'interest_percentage' => $interestPercentage,
                'tenure_months' => $request->tenure_months,
                'monthly_installment' => $installmentAmount,
                'total_amount' => $totalAmount,
                'total_interest' => $totalInterest,
                'effective_rate' => round(($totalInterest / $request->principal_amount) * 100, 2),
            ];

            return $this->successResponse($simulation, 'Loan simulation calculated successfully');

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to calculate simulation: ' . $e->getMessage(), 500);
        }
    }

    public function earlySettlement($id, \App\Http\Requests\EarlySettlementRequest $request): JsonResponse
    {
        try {
            $loan = Loan::with(['user', 'installments'])->findOrFail($id);
            $settledBy = auth()->id();

            $result = $loan->processEarlySettlement($settledBy, $request->settlement_notes);

            $loan->fresh(['user', 'installments']);

            return $this->successResponse(['loan' => $loan, 'settlement_summary' => $result], 'Pinjaman berhasil dilunasi dipercepat');

        } catch (\Exception $e) {
            return $this->errorResponse('Gagal memproses pelunasan: ' . $e->getMessage(), 500);
        }
    }

    public function earlySettlementPreview($id): JsonResponse
    {
        try {
            $loan = Loan::with(['user', 'installments'])->findOrFail($id);

            if (!in_array($loan->status, ['disbursed', 'active'])) {
                return $this->errorResponse('Hanya pinjaman aktif yang dapat dilunasi. Status: ' . $loan->status, 422);
            }

            if ($loan->remaining_principal <= 0) {
                return $this->errorResponse('Pinjaman sudah lunas', 422);
            }

            $paidInstallments = $loan->installments()->whereIn('status', ['paid', 'auto_paid'])->get();
            $pendingInstallments = $loan->installments()->whereIn('status', ['pending', 'overdue'])->get();

            $paidInterest = $paidInstallments->sum('interest_amount');
            $pendingInterest = $pendingInstallments->sum('interest_amount');

            $preview = [
                'loan_number' => $loan->loan_number,
                'original_principal' => $loan->principal_amount,
                'remaining_principal' => $loan->remaining_principal,
                'settlement_amount' => $loan->remaining_principal,
                'interest_saved' => $pendingInterest,
                'paid_installments' => $paidInstallments->count(),
                'pending_installments' => $pendingInstallments->count(),
                'total_interest_paid' => $paidInterest,
                'message' => 'Anda hanya perlu membayar sisa pokok tanpa bunga'
            ];

            return $this->successResponse($preview, 'Preview pelunasan dipercepat');

        } catch (\Exception $e) {
            return $this->errorResponse('Gagal menghitung preview: ' . $e->getMessage(), 500);
        }
    }

    // ==================== IMPORT/EXPORT ====================

    public function downloadTemplate(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Template Pinjaman');
            
            // Header
            $sheet->setCellValue('A1', 'TEMPLATE IMPORT PENGAJUAN PINJAMAN');
            $sheet->mergeCells('A1:H1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4472C4');
            $sheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getRowDimension('1')->setRowHeight(30);
            
            // Instructions
            $sheet->setCellValue('A3', '📋 PETUNJUK PENGISIAN:');
            $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle('A3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFD966');
            
            $instructions = [
                ['No', 'Instruksi'],
                ['1', 'Isi data mulai dari baris 12 (hapus contoh data terlebih dahulu)'],
                ['2', 'ID Member: Lihat sheet "Daftar Member" untuk ID yang valid'],
                ['3', 'ID Kas: Lihat sheet "Daftar Kas" untuk ID kas pinjaman (KAS-I atau KAS-III)'],
                ['4', 'Nominal: Minimal Rp 100.000, tanpa titik/koma'],
                ['5', 'Jangka Waktu: Minimal 6 bulan, maksimal 60 bulan'],
                ['6', 'Tanggal: Format YYYY-MM-DD (contoh: 2026-02-17)'],
                ['7', 'Tujuan: Wajib diisi, maksimal 500 karakter'],
            ];
            
            $row = 4;
            foreach ($instructions as $instruction) {
                $sheet->setCellValue('A' . $row, $instruction[0]);
                $sheet->setCellValue('B' . $row, $instruction[1]);
                if ($instruction[0] === 'No') {
                    $sheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);
                }
                $row++;
            }
            
            $sheet->getStyle('A4:B' . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ]);
            
            // Column Headers
            $headerRow = 11;
            $headers = ['ID Member', 'ID Kas', 'Nominal Pinjaman', 'Jangka Waktu (Bulan)', 'Tanggal Pengajuan', 'Tujuan Pinjaman', 'Catatan', 'Status'];
            
            $column = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($column . $headerRow, $header);
                $sheet->getStyle($column . $headerRow)->getFont()->setBold(true);
                $sheet->getStyle($column . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4472C4');
                $sheet->getStyle($column . $headerRow)->getFont()->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($column . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $column++;
            }
            
            // Sample Data
            $sampleData = [
                [1, 3, 1000000, 12, '2026-02-17', 'Modal usaha', 'Pinjaman pertama', ''],
                [2, 3, 2000000, 24, '2026-02-17', 'Renovasi rumah', '', ''],
            ];
            
            $row = 12;
            foreach ($sampleData as $data) {
                $column = 'A';
                foreach ($data as $value) {
                    $sheet->setCellValue($column . $row, $value);
                    $column++;
                }
                $sheet->getStyle('A' . $row . ':H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7E6E6');
                $row++;
            }
            
            // Formatting
            $sheet->getColumnDimension('A')->setWidth(12);
            $sheet->getColumnDimension('B')->setWidth(10);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(20);
            $sheet->getColumnDimension('E')->setWidth(18);
            $sheet->getColumnDimension('F')->setWidth(35);
            $sheet->getColumnDimension('G')->setWidth(25);
            $sheet->getColumnDimension('H')->setWidth(15);
            
            $sheet->getStyle('A' . $headerRow . ':H' . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ]);
            
            // Member Sheet
            $memberSheet = $spreadsheet->createSheet();
            $memberSheet->setTitle('Daftar Member');
            
            $memberSheet->setCellValue('A1', 'DAFTAR MEMBER AKTIF');
            $memberSheet->mergeCells('A1:D1');
            $memberSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $memberSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $memberSheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('70AD47');
            $memberSheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
            
            $memberHeaders = ['ID', 'NIP/Employee ID', 'Nama Lengkap', 'Status'];
            $column = 'A';
            foreach ($memberHeaders as $header) {
                $memberSheet->setCellValue($column . '2', $header);
                $memberSheet->getStyle($column . '2')->getFont()->setBold(true);
                $memberSheet->getStyle($column . '2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('A9D08E');
                $column++;
            }
            
            $members = User::members()->where('status', 'active')->orderBy('employee_id')->get(['id', 'employee_id', 'full_name', 'status']);
            
            $row = 3;
            foreach ($members as $member) {
                $memberSheet->setCellValue('A' . $row, $member->id);
                $memberSheet->setCellValue('B' . $row, $member->employee_id);
                $memberSheet->setCellValue('C' . $row, $member->full_name);
                $memberSheet->setCellValue('D' . $row, $member->status);
                
                if ($row % 2 == 0) {
                    $memberSheet->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
                }
                
                $row++;
            }
            
            $memberSheet->getStyle('A2:D' . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ]);
            
            $memberSheet->getColumnDimension('A')->setWidth(10);
            $memberSheet->getColumnDimension('B')->setWidth(20);
            $memberSheet->getColumnDimension('C')->setWidth(35);
            $memberSheet->getColumnDimension('D')->setWidth(15);
            
            // Cash Account Sheet
            $cashSheet = $spreadsheet->createSheet();
            $cashSheet->setTitle('Daftar Kas');
            
            $cashSheet->setCellValue('A1', 'DAFTAR KAS PINJAMAN');
            $cashSheet->mergeCells('A1:D1');
            $cashSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $cashSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $cashSheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('5B9BD5');
            $cashSheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
            
            $cashHeaders = ['ID', 'Kode', 'Nama Kas', 'Tipe'];
            $column = 'A';
            foreach ($cashHeaders as $header) {
                $cashSheet->setCellValue($column . '2', $header);
                $cashSheet->getStyle($column . '2')->getFont()->setBold(true);
                $cashSheet->getStyle($column . '2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('9BC2E6');
                $column++;
            }
            
            $cashAccounts = CashAccount::whereIn('type', ['KAS-I', 'KAS-III'])->orderBy('code')->get(['id', 'code', 'name', 'type']);
            
            $row = 3;
            foreach ($cashAccounts as $cash) {
                $cashSheet->setCellValue('A' . $row, $cash->id);
                $cashSheet->setCellValue('B' . $row, $cash->code);
                $cashSheet->setCellValue('C' . $row, $cash->name);
                $cashSheet->setCellValue('D' . $row, $cash->type);
                
                if ($row % 2 == 0) {
                    $cashSheet->getStyle('A' . $row . ':D' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
                }
                
                $row++;
            }
            
            $cashSheet->getStyle('A2:D' . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ]);
            
            $cashSheet->getColumnDimension('A')->setWidth(10);
            $cashSheet->getColumnDimension('B')->setWidth(15);
            $cashSheet->getColumnDimension('C')->setWidth(30);
            $cashSheet->getColumnDimension('D')->setWidth(15);
            
            $spreadsheet->setActiveSheetIndex(0);
            
            // Save
            $filename = 'Template_Pinjaman_' . date('Y-m-d') . '.xlsx';
            $filepath = storage_path('app/temp/' . $filename);
            
            if (!file_exists(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }
            
            $writer = new Xlsx($spreadsheet);
            $writer->save($filepath);
            
            return response()->download($filepath, $filename)->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            \Log::error('Template download error', ['error' => $e->getMessage()]);
            abort(500, 'Gagal membuat template: ' . $e->getMessage());
        }
    }

    public function importExcel(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls|max:5120',
            ]);
            
            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            
            $highestRow = $sheet->getHighestRow();
            
            if ($highestRow < 12) {
                return $this->errorResponse('File Excel tidak memiliki data', 400);
            }
            
            $errors = [];
            $validData = [];
            
            for ($row = 12; $row <= $highestRow; $row++) {
                $userId = $sheet->getCell('A' . $row)->getValue();
                $cashAccountId = $sheet->getCell('B' . $row)->getValue();
                $principalAmount = $sheet->getCell('C' . $row)->getValue();
                $tenureMonths = $sheet->getCell('D' . $row)->getValue();
                $applicationDate = $sheet->getCell('E' . $row)->getValue();
                $loanPurpose = $sheet->getCell('F' . $row)->getValue();
                $notes = $sheet->getCell('G' . $row)->getValue();
                $deductionMethod = $sheet->getCell('H' . $row)->getValue();
                $salaryDeductionPercentage = $sheet->getCell('I' . $row)->getValue();
                $serviceAllowanceDeductionPercentage = $sheet->getCell('J' . $row)->getValue();
                
                if (empty($userId) && empty($principalAmount)) {
                    continue;
                }
                
                $rowData = [
                    'row' => $row,
                    'user_id' => $userId,
                    'cash_account_id' => $cashAccountId,
                    'principal_amount' => $principalAmount,
                    'tenure_months' => $tenureMonths,
                    'application_date' => $applicationDate,
                    'loan_purpose' => $loanPurpose,
                    'notes' => $notes,
                    'deduction_method' => $deductionMethod,
                    'salary_deduction_percentage' => $salaryDeductionPercentage,
                    'service_allowance_deduction_percentage' => $serviceAllowanceDeductionPercentage,
                ];
                
                $validation = $this->validateLoanRow($rowData, $row);
                
                if (!$validation['valid']) {
                    $errors[] = $validation['errors'];
                } else {
                    $validData[] = $validation['data'];
                }
            }
            
            if (!empty($errors)) {
                return $this->errorResponse('Validasi gagal', 422, ['errors' => $errors]);
            }
            
            if (empty($validData)) {
                return $this->errorResponse('Tidak ada data valid', 400);
            }
            
            DB::beginTransaction();
            
            try {
                $results = ['success' => [], 'failed' => []];
                
                foreach ($validData as $data) {
                    try {
                        $user = User::find($data['user_id']);
                        $cashAccount = CashAccount::find($data['cash_account_id']);
                        
                        $interestRate = $cashAccount->currentLoanRate()->first();
                        $interestPercentage = $interestRate ? $interestRate->rate_percentage : 12.0;
                        
                        $installmentAmount = Loan::calculateInstallment(
                            $data['principal_amount'],
                            $interestPercentage,
                            $data['tenure_months']
                        );
                        
                        $loanNumber = Loan::generateLoanNumber();
                        
                        $loan = Loan::create([
                            'user_id' => $data['user_id'],
                            'cash_account_id' => $data['cash_account_id'],
                            'loan_number' => $loanNumber,
                            'principal_amount' => $data['principal_amount'],
                            'interest_percentage' => $interestPercentage,
                            'tenure_months' => $data['tenure_months'],
                            'installment_amount' => $installmentAmount,
                            'status' => 'pending',
                            'application_date' => $data['application_date'],
                            'loan_purpose' => $data['loan_purpose'],
                        ]);
                        
                        $results['success'][] = [
                            'row' => $data['row'],
                            'loan_number' => $loanNumber,
                            'member' => $user->full_name,
                            'amount' => $data['principal_amount'],
                        ];
                        
                    } catch (\Exception $e) {
                        $results['failed'][] = [
                            'row' => $data['row'],
                            'error' => $e->getMessage(),
                        ];
                    }
                }
                
                DB::commit();
                
                return $this->successResponse([
                    'total_processed' => count($validData),
                    'success_count' => count($results['success']),
                    'failed_count' => count($results['failed']),
                    'results' => $results,
                ], 'Import selesai');
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Gagal import: ' . $e->getMessage(), 500);
        }
    }

    private function validateLoanRow(array $rowData, int $rowNumber): array
    {
        $errors = [];
        
        if (empty($rowData['user_id'])) {
            $errors[] = "ID Member tidak boleh kosong";
        } else {
            $user = User::find($rowData['user_id']);
            if (!$user) {
                $errors[] = "ID Member tidak ditemukan";
            } elseif (!$user->isMember()) {
                $errors[] = "User bukan member";
            } elseif ($user->status !== 'active') {
                $errors[] = "Member tidak aktif";
            }
        }
        
        if (empty($rowData['cash_account_id'])) {
            $errors[] = "ID Kas tidak boleh kosong";
        } else {
            $cashAccount = CashAccount::find($rowData['cash_account_id']);
            if (!$cashAccount) {
                $errors[] = "ID Kas tidak ditemukan";
            } elseif (!in_array($cashAccount->type, ['KAS-I', 'KAS-III'])) {
                $errors[] = "Kas harus KAS-I atau KAS-III";
            }
        }
        
        $amount = str_replace(['.', ',', ' ', 'Rp'], '', $rowData['principal_amount']);
        if (empty($amount)) {
            $errors[] = "Nominal tidak boleh kosong";
        } elseif (!is_numeric($amount)) {
            $errors[] = "Nominal harus berupa angka";
        } elseif ($amount < 100000) {
            $errors[] = "Nominal minimal Rp 100.000";
        }
        
        if (empty($rowData['tenure_months'])) {
            $errors[] = "Jangka waktu tidak boleh kosong";
        } elseif (!is_numeric($rowData['tenure_months'])) {
            $errors[] = "Jangka waktu harus berupa angka";
        } elseif ($rowData['tenure_months'] < 6 || $rowData['tenure_months'] > 60) {
            $errors[] = "Jangka waktu harus 6-60 bulan";
        }
        
        if (empty($rowData['application_date'])) {
            $errors[] = "Tanggal pengajuan tidak boleh kosong";
        } else {
            try {
                $date = \Carbon\Carbon::parse($rowData['application_date']);
            } catch (\Exception $e) {
                $errors[] = "Format tanggal tidak valid";
            }
        }
        
        if (empty($rowData['loan_purpose'])) {
            $errors[] = "Tujuan pinjaman tidak boleh kosong";
        }
        
        if (!empty($errors)) {
            return ['valid' => false, 'errors' => ['row' => $rowNumber, 'messages' => $errors]];
        }
        
        return [
            'valid' => true,
            'data' => [
                'row' => $rowNumber,
                'user_id' => $rowData['user_id'],
                'cash_account_id' => $rowData['cash_account_id'],
                'principal_amount' => (float) $amount,
                'tenure_months' => (int) $rowData['tenure_months'],
                'application_date' => \Carbon\Carbon::parse($rowData['application_date'])->format('Y-m-d'),
                'loan_purpose' => $rowData['loan_purpose'],
            ],
        ];
    }

    public function exportExcel(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $query = Loan::with(['user:id,full_name,employee_id', 'cashAccount:id,code,name']);
            
            if ($request->has('status')) {
                $query->byStatus($request->status);
                $statusLabel = ucfirst($request->status);
            } else {
                $statusLabel = "Semua Status";
            }
            
            if ($request->has('user_id')) {
                $query->byUser($request->user_id);
            }
            
            if ($request->has('cash_account_id')) {
                $query->byCashAccount($request->cash_account_id);
            }
            
            $loans = $query->orderBy('application_date', 'desc')->get();
            
            if ($loans->isEmpty()) {
                abort(404, 'Tidak ada data untuk diekspor');
            }
            
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Data Pinjaman');
            
            // Header
            $sheet->setCellValue('A1', 'LAPORAN DATA PINJAMAN');
            $sheet->mergeCells('A1:K1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            $sheet->setCellValue('A2', 'Status: ' . $statusLabel);
            $sheet->mergeCells('A2:K2');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            $sheet->setCellValue('A3', 'Dicetak: ' . now()->format('d F Y H:i'));
            $sheet->mergeCells('A3:K3');
            $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            // Summary
            $totalPrincipal = $loans->sum('principal_amount');
            $totalRemaining = $loans->whereIn('status', ['disbursed', 'active'])->sum('remaining_principal');
            
            $sheet->setCellValue('A5', 'RINGKASAN:');
            $sheet->getStyle('A5')->getFont()->setBold(true);
            
            $sheet->setCellValue('A6', 'Total Pinjaman:');
            $sheet->setCellValue('B6', $loans->count());
            
            $sheet->setCellValue('A7', 'Total Nominal:');
            $sheet->setCellValue('B7', $totalPrincipal);
            $sheet->getStyle('B7')->getNumberFormat()->setFormatCode('#,##0');
            
            $sheet->setCellValue('A8', 'Total Sisa Pokok:');
            $sheet->setCellValue('B8', $totalRemaining);
            $sheet->getStyle('B8')->getNumberFormat()->setFormatCode('#,##0');
            
            // Column headers
            $headers = ['No', 'No. Pinjaman', 'NIP', 'Nama', 'Kas', 'Nominal', 'Bunga %', 'Tenor', 'Cicilan/Bulan', 'Sisa Pokok', 'Status'];
            $column = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($column . '10', $header);
                $sheet->getStyle($column . '10')->getFont()->setBold(true);
                $sheet->getStyle($column . '10')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4472C4');
                $sheet->getStyle($column . '10')->getFont()->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($column . '10')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $column++;
            }
            
            // Data rows
            $row = 11;
            $no = 1;
            foreach ($loans as $loan) {
                $sheet->setCellValue('A' . $row, $no++);
                $sheet->setCellValue('B' . $row, $loan->loan_number);
                $sheet->setCellValue('C' . $row, $loan->user->employee_id ?? 'N/A');
                $sheet->setCellValue('D' . $row, $loan->user->full_name ?? 'Unknown');
                $sheet->setCellValue('E' . $row, $loan->cashAccount->code ?? 'N/A');
                $sheet->setCellValue('F' . $row, $loan->principal_amount);
                $sheet->setCellValue('G' . $row, $loan->interest_percentage);
                $sheet->setCellValue('H' . $row, $loan->tenure_months);
                $sheet->setCellValue('I' . $row, $loan->installment_amount);
                $sheet->setCellValue('J' . $row, $loan->remaining_principal);
                $sheet->setCellValue('K' . $row, $loan->status_name);
                
                $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('I' . $row)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                
                $row++;
            }
            
            // Column widths
            $sheet->getColumnDimension('A')->setWidth(6);
            $sheet->getColumnDimension('B')->setWidth(18);
            $sheet->getColumnDimension('C')->setWidth(15);
            $sheet->getColumnDimension('D')->setWidth(30);
            $sheet->getColumnDimension('E')->setWidth(12);
            $sheet->getColumnDimension('F')->setWidth(15);
            $sheet->getColumnDimension('G')->setWidth(10);
            $sheet->getColumnDimension('H')->setWidth(10);
            $sheet->getColumnDimension('I')->setWidth(15);
            $sheet->getColumnDimension('J')->setWidth(15);
            $sheet->getColumnDimension('K')->setWidth(15);
            
            // Borders
            $lastRow = $row - 1;
            $sheet->getStyle('A10:K' . $lastRow)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ]);
            
            // Save
            $filename = 'Pinjaman_' . $statusLabel . '_' . date('Y-m-d') . '.xlsx';
            $filepath = storage_path('app/temp/' . $filename);
            
            $writer = new Xlsx($spreadsheet);
            $writer->save($filepath);
            
            return response()->download($filepath, $filename)->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            \Log::error('Export error', ['error' => $e->getMessage()]);
            abort(500, 'Gagal export: ' . $e->getMessage());
        }
    }
}