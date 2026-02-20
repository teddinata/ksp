<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavingRequest;
use App\Http\Requests\ApproveSavingRequest;
use App\Models\Saving;
use App\Models\CashAccount;
use App\Models\User;
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

class SavingController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Saving::with(['user:id,full_name,employee_id', 'cashAccount:id,code,name', 'approvedBy:id,full_name']);

            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            if ($request->has('user_id') && ($user->isAdmin() || $user->isManager())) {
                $query->byUser($request->user_id);
            }

            if ($request->has('cash_account_id')) {
                $query->byCashAccount($request->cash_account_id);
            }

            if ($request->has('savings_type')) {
                $query->byType($request->savings_type);
            }

            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            if ($request->has('start_date') && $request->has('end_date')) {
                $query->dateRange($request->start_date, $request->end_date);
            }

            if ($request->has('search') && ($user->isAdmin() || $user->isManager())) {
                $search = $request->search;
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('employee_id', 'like', "%{$search}%");
                });
            }

            $sortBy = $request->get('sort_by', 'transaction_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->get('per_page', 15);

            if ($request->has('all') && $request->boolean('all')) {
                $savings = $query->get();
                return $this->successResponse($savings, 'Savings retrieved successfully');
            }
            else {
                $savings = $query->paginate($perPage);
                return $this->paginatedResponse($savings, 'Savings retrieved successfully');
            }

        }
        catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve savings: ' . $e->getMessage(), 500);
        }
    }

    public function store(SavingRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $cashAccount = CashAccount::findOrFail($request->cash_account_id);

            $interestRate = $cashAccount->currentSavingsRate()->first();
            $interestPercentage = $interestRate ? $interestRate->rate_percentage : 0;

            $finalAmount = Saving::calculateFinalAmount($request->amount, $interestPercentage);

            // ✅ Resolve saving_type_id dan savings_type dari kedua arah
            $savingTypeId = $request->saving_type_id;
            $savingsType = $request->savings_type;

            // Jika hanya saving_type_id dikirim → resolve savings_type dari DB
            if ($savingTypeId && !$savingsType) {
                $savingType = \App\Models\SavingType::find($savingTypeId);
                if (!$savingType) {
                    return $this->errorResponse('Saving type tidak ditemukan', 404);
                }
                // Map code ke savings_type enum
                $codeMapping = [
                    'POKOK'    => 'principal',
                    'WAJIB'    => 'mandatory',
                    'SUKARELA' => 'voluntary',
                    'HARIRAYA' => 'holiday',
                ];
                $savingsType = $codeMapping[$savingType->code] ?? strtolower($savingType->code);
            }

            // Jika hanya savings_type dikirim → resolve saving_type_id dari DB
            if ($savingsType && !$savingTypeId) {
                $typeMapping = [
                    'principal' => 'POKOK',
                    'mandatory' => 'WAJIB',
                    'voluntary' => 'SUKARELA',
                    'holiday'   => 'HARIRAYA',
                ];
                if (isset($typeMapping[$savingsType])) {
                    $savingType = \App\Models\SavingType::where('code', $typeMapping[$savingsType])->first();
                    $savingTypeId = $savingType ? $savingType->id : null;
                }
            }

            // Validasi: salah satu harus ada
            if (!$savingsType) {
                return $this->errorResponse('savings_type atau saving_type_id harus diisi', 422);
            }

            DB::beginTransaction();

            $saving = Saving::create([
                'user_id'            => $request->user_id,
                'cash_account_id'    => $request->cash_account_id,
                'saving_type_id'     => $savingTypeId,
                'savings_type'       => $savingsType,  // ✅ Selalu terisi
                'amount'             => $request->amount,
                'interest_percentage'=> $interestPercentage,
                'final_amount'       => $finalAmount,
                'transaction_date'   => $request->transaction_date,
                'status'             => 'approved',
                'notes'              => $request->notes,
                'approved_by'        => $user->id,
            ]);

            $cashAccount->updateBalance($request->amount, 'add');
            AutoJournalService::savingApproved($saving, $user->id);

            DB::commit();

            $saving->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name', 'approvedBy:id,full_name', 'savingType']);

            return $this->successResponse($saving, 'Saving transaction created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Failed to create saving: ' . $e->getMessage(), 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = Saving::with(['user:id,full_name,employee_id,email', 'cashAccount:id,code,name', 'approvedBy:id,full_name']);

            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            $saving = $query->findOrFail($id);

            return $this->successResponse($saving, 'Saving retrieved successfully');

        }
        catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve saving: ' . $e->getMessage(), 500);
        }
    }

    public function update(SavingRequest $request, int $id): JsonResponse
    {
        try {
            $saving = Saving::findOrFail($id);

            if (!$saving->isPending()) {
                return $this->errorResponse('Cannot update saving that is already ' . $saving->status, 400);
            }

            $cashAccount = CashAccount::findOrFail($request->cash_account_id);
            $interestRate = $cashAccount->currentSavingsRate()->first();
            $interestPercentage = $interestRate ? $interestRate->rate_percentage : 0;

            $finalAmount = Saving::calculateFinalAmount($request->amount, $interestPercentage);

            // Determine saving_type_id if changing type
            $savingTypeId = $saving->saving_type_id;
            if ($request->has('saving_type_id')) {
                $savingTypeId = $request->saving_type_id;
            }
            elseif ($request->has('savings_type')) {
                $typeMapping = [
                    'principal' => 'POKOK',
                    'mandatory' => 'WAJIB',
                    'voluntary' => 'SUKARELA',
                    'holiday' => 'HARIRAYA',
                ];
                if (isset($typeMapping[$request->savings_type])) {
                    $savingType = \App\Models\SavingType::where('code', $typeMapping[$request->savings_type])->first();
                    $savingTypeId = $savingType ? $savingType->id : $savingTypeId;
                }
            }

            $saving->update([
                'user_id' => $request->user_id,
                'cash_account_id' => $request->cash_account_id,
                'saving_type_id' => $savingTypeId,
                'savings_type' => $request->savings_type,
                'amount' => $request->amount,
                'interest_percentage' => $interestPercentage,
                'final_amount' => $finalAmount,
                'transaction_date' => $request->transaction_date,
                'notes' => $request->notes,
            ]);

            $saving->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name']);

            return $this->successResponse($saving, 'Saving updated successfully');

        }
        catch (\Exception $e) {
            return $this->errorResponse('Failed to update saving: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $saving = Saving::findOrFail($id);

            if ($saving->isApproved()) {
                return $this->errorResponse('Cannot delete approved saving. Please create a reversal transaction instead.', 400);
            }

            $saving->delete();

            return $this->successResponse(null, 'Saving deleted successfully');

        }
        catch (\Exception $e) {
            return $this->errorResponse('Failed to delete saving: ' . $e->getMessage(), 500);
        }
    }

    public function approve(ApproveSavingRequest $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $saving = Saving::findOrFail($id);

            if (!$saving->isPending()) {
                return $this->errorResponse('Saving is already ' . $saving->status, 400);
            }

            DB::beginTransaction();

            $saving->update([
                'status' => $request->status,
                'notes' => $request->notes ?? $saving->notes,
                'approved_by' => $user->id,
            ]);

            if ($request->status === 'approved') {
                $cashAccount = CashAccount::find($saving->cash_account_id);
                if ($cashAccount) {
                    $cashAccount->updateBalance($saving->amount, 'add');
                }

                AutoJournalService::savingApproved($saving, $user->id);
            }

            DB::commit();

            $saving->load(['user:id,full_name,employee_id', 'cashAccount:id,code,name', 'approvedBy:id,full_name']);

            $message = $request->status === 'approved' ? 'Saving approved successfully' : 'Saving rejected successfully';

            return $this->successResponse($saving, $message);

        }
        catch (\Exception $e) {
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

            $summary = [
                'user' => User::find($userId)->only(['id', 'full_name', 'employee_id']),
                'total_savings' => Saving::getTotalForUser($userId),
                'by_type' => [
                    'principal' => Saving::getTotalByType($userId, 'principal'),
                    'mandatory' => Saving::getTotalByType($userId, 'mandatory'),
                    'voluntary' => Saving::getTotalByType($userId, 'voluntary'),
                    'holiday' => Saving::getTotalByType($userId, 'holiday'),
                ],
                'transaction_count' => Saving::where('user_id', $userId)->where('status', 'approved')->count(),
                'pending_count' => Saving::where('user_id', $userId)->where('status', 'pending')->count(),
            ];

            return $this->successResponse($summary, 'Savings summary retrieved successfully');

        }
        catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve summary: ' . $e->getMessage(), 500);
        }
    }

    public function getByType(Request $request, string $type): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!in_array($type, ['principal', 'mandatory', 'voluntary', 'holiday'])) {
                return $this->errorResponse('Invalid savings type', 400);
            }

            $query = Saving::with(['user:id,full_name,employee_id', 'cashAccount:id,code,name'])
                ->byType($type)
                ->approved();

            if ($user->isMember()) {
                $query->byUser($user->id);
            }
            elseif ($request->has('user_id')) {
                $query->byUser($request->user_id);
            }

            $savings = $query->orderBy('transaction_date', 'desc')->get();

            return $this->successResponse($savings, ucfirst($type) . ' savings retrieved successfully');

        }
        catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve savings: ' . $e->getMessage(), 500);
        }
    }

    // ==================== IMPORT/EXPORT ====================

    public function downloadTemplate(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Template Simpanan');

            // Header
            $sheet->setCellValue('A1', 'TEMPLATE IMPORT SIMPANAN');
            $sheet->mergeCells('A1:G1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('70AD47');
            $sheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getRowDimension('1')->setRowHeight(30);

            // Instructions
            $sheet->setCellValue('A3', '📋 PETUNJUK PENGISIAN:');
            $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle('A3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFD966');

            $instructions = [
                ['No', 'Instruksi'],
                ['1', 'Isi data mulai dari baris 13 (hapus contoh data terlebih dahulu)'],
                ['2', 'ID Member: Lihat sheet "Daftar Member" untuk ID yang valid'],
                ['3', 'ID Kas: Lihat sheet "Daftar Kas" untuk ID kas simpanan (KAS-II)'],
                ['4', 'Jenis: principal, mandatory, voluntary, atau holiday'],
                ['5', 'Nominal: Minimal Rp 10.000, tanpa titik/koma'],
                ['6', 'Tanggal: Format YYYY-MM-DD (contoh: 2026-02-17)'],
                ['7', 'Catatan: Opsional, bisa dikosongkan'],
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

            // Type examples
            $sheet->setCellValue('D3', '📝 JENIS SIMPANAN:');
            $sheet->getStyle('D3')->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle('D3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('C6E0B4');
            $sheet->mergeCells('D3:E3');

            $typeExamples = [
                ['Kode', 'Keterangan'],
                ['principal', 'Simpanan Pokok (sekali, saat daftar)'],
                ['mandatory', 'Simpanan Wajib (rutin bulanan)'],
                ['voluntary', 'Simpanan Sukarela (kapan saja)'],
                ['holiday', 'Simpanan Hari Raya (menjelang hari raya)'],
            ];

            $row = 4;
            foreach ($typeExamples as $example) {
                $sheet->setCellValue('D' . $row, $example[0]);
                $sheet->setCellValue('E' . $row, $example[1]);

                if ($example[0] === 'Kode') {
                    $sheet->getStyle('D' . $row . ':E' . $row)->getFont()->setBold(true);
                }

                $row++;
            }

            $sheet->getStyle('D4:E' . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ]);

            // Column Headers
            $headerRow = 12;
            $headers = ['ID Member', 'ID Kas', 'Jenis Simpanan', 'Nominal', 'Tanggal Transaksi', 'Catatan', 'Status'];

            $column = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($column . $headerRow, $header);
                $sheet->getStyle($column . $headerRow)->getFont()->setBold(true);
                $sheet->getStyle($column . $headerRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('70AD47');
                $sheet->getStyle($column . $headerRow)->getFont()->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($column . $headerRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $column++;
            }

            // Sample Data
            $sampleData = [
                [1, 2, 'principal', 100000, '2026-02-17', 'Simpanan pokok awal', ''],
                [2, 2, 'mandatory', 50000, '2026-02-17', 'Simpanan wajib Februari', ''],
                [3, 2, 'voluntary', 200000, '2026-02-17', '', ''],
            ];

            $row = 13;
            foreach ($sampleData as $data) {
                $column = 'A';
                foreach ($data as $value) {
                    $sheet->setCellValue($column . $row, $value);
                    $column++;
                }
                $sheet->getStyle('A' . $row . ':G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7E6E6');
                $row++;
            }

            // Formatting
            $sheet->getColumnDimension('A')->setWidth(12);
            $sheet->getColumnDimension('B')->setWidth(10);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(15);
            $sheet->getColumnDimension('E')->setWidth(18);
            $sheet->getColumnDimension('F')->setWidth(35);
            $sheet->getColumnDimension('G')->setWidth(15);

            $sheet->getStyle('A' . $headerRow . ':G' . ($row - 1))->applyFromArray([
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

            $cashSheet->setCellValue('A1', 'DAFTAR KAS SIMPANAN');
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

            $cashAccounts = CashAccount::where('type', 'KAS-II')->orderBy('code')->get(['id', 'code', 'name', 'type']);

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
            $filename = 'Template_Simpanan_' . date('Y-m-d') . '.xlsx';
            $filepath = storage_path('app/temp/' . $filename);

            if (!file_exists(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save($filepath);

            return response()->download($filepath, $filename)->deleteFileAfterSend(true);

        }
        catch (\Exception $e) {
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

            if ($highestRow < 13) {
                return $this->errorResponse('File Excel tidak memiliki data', 400);
            }

            $errors = [];
            $validData = [];
            $approvedBy = auth()->id();

            for ($row = 13; $row <= $highestRow; $row++) {
                $userId = $sheet->getCell('A' . $row)->getValue();
                $cashAccountId = $sheet->getCell('B' . $row)->getValue();
                $savingsType = $sheet->getCell('C' . $row)->getValue();
                $amount = $sheet->getCell('D' . $row)->getValue();
                $transactionDate = $sheet->getCell('E' . $row)->getValue();
                $notes = $sheet->getCell('F' . $row)->getValue();

                if (empty($userId) && empty($amount)) {
                    continue;
                }

                $rowData = [
                    'row' => $row,
                    'user_id' => $userId,
                    'cash_account_id' => $cashAccountId,
                    'savings_type' => $savingsType,
                    'amount' => $amount,
                    'transaction_date' => $transactionDate,
                    'notes' => $notes,
                ];

                $validation = $this->validateSavingRow($rowData, $row);

                if (!$validation['valid']) {
                    $errors[] = $validation['errors'];
                }
                else {
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

                        $interestRate = $cashAccount->currentSavingsRate()->first();
                        $interestPercentage = $interestRate ? $interestRate->rate_percentage : 0;

                        $finalAmount = Saving::calculateFinalAmount($data['amount'], $interestPercentage);

                        $saving = Saving::create([
                            'user_id' => $data['user_id'],
                            'cash_account_id' => $data['cash_account_id'],
                            'savings_type' => $data['savings_type'],
                            'amount' => $data['amount'],
                            'interest_percentage' => $interestPercentage,
                            'final_amount' => $finalAmount,
                            'transaction_date' => $data['transaction_date'],
                            'status' => 'approved',
                            'notes' => $data['notes'],
                            'approved_by' => $approvedBy,
                        ]);

                        $cashAccount->updateBalance($data['amount'], 'add');

                        AutoJournalService::savingApproved($saving, $approvedBy);

                        $results['success'][] = [
                            'row' => $data['row'],
                            'member' => $user->full_name,
                            'type' => $data['savings_type'],
                            'amount' => $data['amount'],
                        ];

                    }
                    catch (\Exception $e) {
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

            }
            catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        }
        catch (\Exception $e) {
            return $this->errorResponse('Gagal import: ' . $e->getMessage(), 500);
        }
    }

    private function validateSavingRow(array $rowData, int $rowNumber): array
    {
        $errors = [];

        if (empty($rowData['user_id'])) {
            $errors[] = "ID Member tidak boleh kosong";
        }
        else {
            $user = User::find($rowData['user_id']);
            if (!$user) {
                $errors[] = "ID Member tidak ditemukan";
            }
            elseif (!$user->isMember()) {
                $errors[] = "User bukan member";
            }
            elseif ($user->status !== 'active') {
                $errors[] = "Member tidak aktif";
            }
        }

        if (empty($rowData['cash_account_id'])) {
            $errors[] = "ID Kas tidak boleh kosong";
        }
        else {
            $cashAccount = CashAccount::find($rowData['cash_account_id']);
            if (!$cashAccount) {
                $errors[] = "ID Kas tidak ditemukan";
            }
            elseif ($cashAccount->type !== 'KAS-II') {
                $errors[] = "Kas harus KAS-II (Simpanan)";
            }
        }

        if (empty($rowData['savings_type'])) {
            $errors[] = "Jenis simpanan tidak boleh kosong";
        }
        elseif (!in_array($rowData['savings_type'], ['principal', 'mandatory', 'voluntary', 'holiday'])) {
            $errors[] = "Jenis simpanan tidak valid. Harus: principal, mandatory, voluntary, atau holiday";
        }

        $amount = str_replace(['.', ',', ' ', 'Rp'], '', $rowData['amount']);
        if (empty($amount)) {
            $errors[] = "Nominal tidak boleh kosong";
        }
        elseif (!is_numeric($amount)) {
            $errors[] = "Nominal harus berupa angka";
        }
        elseif ($amount < 10000) {
            $errors[] = "Nominal minimal Rp 10.000";
        }

        if (empty($rowData['transaction_date'])) {
            $errors[] = "Tanggal transaksi tidak boleh kosong";
        }
        else {
            try {
                $date = \Carbon\Carbon::parse($rowData['transaction_date']);
            }
            catch (\Exception $e) {
                $errors[] = "Format tanggal tidak valid";
            }
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
                'savings_type' => $rowData['savings_type'],
                'amount' => (float)$amount,
                'transaction_date' => \Carbon\Carbon::parse($rowData['transaction_date'])->format('Y-m-d'),
                'notes' => $rowData['notes'],
            ],
        ];
    }

    public function exportExcel(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $query = Saving::with(['user:id,full_name,employee_id', 'cashAccount:id,code,name']);

            if ($request->has('savings_type')) {
                $query->byType($request->savings_type);
                $typeLabel = ucfirst($request->savings_type);
            }
            else {
                $typeLabel = "Semua Jenis";
            }

            if ($request->has('user_id')) {
                $query->byUser($request->user_id);
            }

            if ($request->has('cash_account_id')) {
                $query->byCashAccount($request->cash_account_id);
            }

            if ($request->has('start_date') && $request->has('end_date')) {
                $query->dateRange($request->start_date, $request->end_date);
                $periodLabel = $request->start_date . ' s/d ' . $request->end_date;
            }
            else {
                $periodLabel = "Semua Periode";
            }

            $savings = $query->orderBy('transaction_date', 'desc')->get();

            if ($savings->isEmpty()) {
                abort(404, 'Tidak ada data untuk diekspor');
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Data Simpanan');

            // Header
            $sheet->setCellValue('A1', 'LAPORAN DATA SIMPANAN');
            $sheet->mergeCells('A1:H1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->setCellValue('A2', 'Jenis: ' . $typeLabel);
            $sheet->mergeCells('A2:H2');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->setCellValue('A3', 'Periode: ' . $periodLabel);
            $sheet->mergeCells('A3:H3');
            $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->setCellValue('A4', 'Dicetak: ' . now()->format('d F Y H:i'));
            $sheet->mergeCells('A4:H4');
            $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Summary
            $totalAmount = $savings->sum('amount');
            $totalFinal = $savings->sum('final_amount');

            $sheet->setCellValue('A6', 'RINGKASAN:');
            $sheet->getStyle('A6')->getFont()->setBold(true);

            $sheet->setCellValue('A7', 'Total Transaksi:');
            $sheet->setCellValue('B7', $savings->count());

            $sheet->setCellValue('A8', 'Total Nominal:');
            $sheet->setCellValue('B8', $totalAmount);
            $sheet->getStyle('B8')->getNumberFormat()->setFormatCode('#,##0');

            $sheet->setCellValue('A9', 'Total dengan Bunga:');
            $sheet->setCellValue('B9', $totalFinal);
            $sheet->getStyle('B9')->getNumberFormat()->setFormatCode('#,##0');

            // Column headers
            $headers = ['No', 'Tanggal', 'NIP', 'Nama', 'Jenis', 'Kas', 'Nominal', 'Status'];
            $column = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($column . '11', $header);
                $sheet->getStyle($column . '11')->getFont()->setBold(true);
                $sheet->getStyle($column . '11')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('70AD47');
                $sheet->getStyle($column . '11')->getFont()->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($column . '11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $column++;
            }

            // Data rows
            $row = 12;
            $no = 1;
            foreach ($savings as $saving) {
                $sheet->setCellValue('A' . $row, $no++);
                $sheet->setCellValue('B' . $row, $saving->transaction_date->format('Y-m-d'));
                $sheet->setCellValue('C' . $row, $saving->user->employee_id ?? 'N/A');
                $sheet->setCellValue('D' . $row, $saving->user->full_name ?? 'Unknown');
                $sheet->setCellValue('E' . $row, ucfirst($saving->savings_type));
                $sheet->setCellValue('F' . $row, $saving->cashAccount->code ?? 'N/A');
                $sheet->setCellValue('G' . $row, $saving->amount);
                $sheet->setCellValue('H' . $row, $saving->status_name);

                $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $row++;
            }

            // Column widths
            $sheet->getColumnDimension('A')->setWidth(6);
            $sheet->getColumnDimension('B')->setWidth(12);
            $sheet->getColumnDimension('C')->setWidth(15);
            $sheet->getColumnDimension('D')->setWidth(30);
            $sheet->getColumnDimension('E')->setWidth(15);
            $sheet->getColumnDimension('F')->setWidth(12);
            $sheet->getColumnDimension('G')->setWidth(15);
            $sheet->getColumnDimension('H')->setWidth(15);

            // Borders
            $lastRow = $row - 1;
            $sheet->getStyle('A11:H' . $lastRow)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ]);

            // Save
            $filename = 'Simpanan_' . $typeLabel . '_' . date('Y-m-d') . '.xlsx';
            $filepath = storage_path('app/temp/' . $filename);

            $writer = new Xlsx($spreadsheet);
            $writer->save($filepath);

            return response()->download($filepath, $filename)->deleteFileAfterSend(true);

        }
        catch (\Exception $e) {
            \Log::error('Export error', ['error' => $e->getMessage()]);
            abort(500, 'Gagal export: ' . $e->getMessage());
        }
    }
}