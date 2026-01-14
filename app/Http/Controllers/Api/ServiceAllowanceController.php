<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceAllowance;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ServiceAllowanceController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of service allowances.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = ServiceAllowance::with([
                'user:id,full_name,employee_id',
                'distributedBy:id,full_name'
            ]);

            // Access Control: Member only sees own allowances
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            // Filters
            if ($request->has('user_id') && ($user->isAdmin() || $user->isManager())) {
                $query->byUser($request->user_id);
            }

            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            if ($request->has('year')) {
                $query->byYear($request->year);
            }

            if ($request->has('month') && $request->has('year')) {
                $query->byPeriod($request->month, $request->year);
            }

            // Sort
            $sortBy = $request->get('sort_by', 'period_year');
            $sortOrder = $request->get('sort_order', 'desc');
            
            $query->orderBy($sortBy, $sortOrder);
            
            if ($sortBy !== 'period_month') {
                $query->orderBy('period_month', 'desc');
            }

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                $allowances = $query->get();
            } else {
                $allowances = $query->paginate($perPage);
            }

            return $request->has('all') 
                ? $this->successResponse($allowances, 'Service allowances retrieved successfully')
                : $this->paginatedResponse($allowances, 'Service allowances retrieved successfully');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve service allowances: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified service allowance.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $user = auth()->user();
            $query = ServiceAllowance::with([
                'user:id,full_name,employee_id,email',
                'distributedBy:id,full_name'
            ]);

            // Access Control
            if ($user->isMember()) {
                $query->byUser($user->id);
            }

            $allowance = $query->findOrFail($id);

            return $this->successResponse(
                $allowance,
                'Service allowance retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Service allowance not found or access denied', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve service allowance: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * ✅ NEW: Store (input manual) jasa pelayanan untuk 1 member
     * 
     * Business Logic:
     * - Admin/Manager input manual per member per period
     * - System auto-potong cicilan bulan itu
     * - Jika kurang, member bayar sisa
     * - Jika lebih, sisa dikembalikan
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // Validation
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'period_month' => 'required|integer|min:1|max:12',
                'period_year' => 'required|integer|min:2020|max:2100',
                'received_amount' => 'required|numeric|min:0',
                'notes' => 'nullable|string',
            ]);

            $member = User::findOrFail($validated['user_id']);

            // Check if member role
            if (!$member->isMember()) {
                return $this->errorResponse(
                    'User is not a member',
                    400
                );
            }

            // Process service allowance
            $result = ServiceAllowance::processForMember(
                $member,
                $validated['period_month'],
                $validated['period_year'],
                $validated['received_amount'],
                $user->id,
                $validated['notes'] ?? null
            );

            return $this->successResponse(
                $result,
                'Jasa pelayanan berhasil diproses',
                201
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $e->errors()
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to process service allowance: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Preview calculation before processing
     */
    public function preview(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id',
                'period_month' => 'required|integer|min:1|max:12',
                'period_year' => 'required|integer|min:2020|max:2100',
                'received_amount' => 'required|numeric|min:0',
            ]);

            $member = User::findOrFail($request->user_id);

            // Get installments for preview
            $startDate = \Carbon\Carbon::create($request->period_year, $request->period_month, 1)->startOfMonth();
            $endDate = \Carbon\Carbon::create($request->period_year, $request->period_month, 1)->endOfMonth();
            
            $installments = \App\Models\Installment::whereHas('loan', function($q) use ($member) {
                $q->where('user_id', $member->id)
                  ->whereIn('status', ['disbursed', 'active']);
            })
            ->where('status', 'pending')
            ->whereBetween('due_date', [$startDate, $endDate])
            ->with('loan:id,loan_number')
            ->get();
            
            $totalDue = $installments->sum('total_amount');
            $receivedAmount = $request->received_amount;
            
            // Calculate preview
            if ($receivedAmount >= $totalDue) {
                $scenario = 'sufficient';
                $paidAmount = $totalDue;
                $remaining = $receivedAmount - $totalDue;
                $memberMustPay = 0;
            } else {
                $scenario = 'insufficient';
                $paidAmount = $receivedAmount;
                $remaining = 0;
                $memberMustPay = $totalDue - $receivedAmount;
            }

            return $this->successResponse([
                'member' => $member->only(['id', 'full_name', 'employee_id']),
                'period' => \Carbon\Carbon::create($request->period_year, $request->period_month, 1)->format('F Y'),
                'received_amount' => $receivedAmount,
                'installments' => $installments->map(function($inst) {
                    return [
                        'id' => $inst->id,
                        'loan_number' => $inst->loan->loan_number,
                        'installment_number' => $inst->installment_number,
                        'amount' => $inst->total_amount,
                        'due_date' => $inst->due_date->format('Y-m-d'),
                    ];
                }),
                'calculation' => [
                    'total_installments_due' => $totalDue,
                    'will_be_paid_from_allowance' => $paidAmount,
                    'remaining_for_member' => $remaining,
                    'member_must_pay' => $memberMustPay,
                    'scenario' => $scenario,
                    'message' => $memberMustPay > 0
                        ? "Jasa pelayanan kurang. Member harus bayar sisa: Rp " . number_format($memberMustPay, 0, ',', '.')
                        : ($remaining > 0
                            ? "Jasa pelayanan cukup. Sisa untuk member: Rp " . number_format($remaining, 0, ',', '.')
                            : "Jasa pelayanan pas untuk bayar cicilan."
                        ),
                ],
            ], 'Preview calculation retrieved successfully');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to calculate preview: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get service allowance summary for a period
     */
    public function periodSummary(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'month' => 'required|integer|min:1|max:12',
                'year' => 'required|integer|min:2020|max:2100',
            ]);

            $month = $request->month;
            $year = $request->year;

            $allowances = ServiceAllowance::with('user:id,full_name,employee_id')
                ->byPeriod($month, $year)
                ->get();

            if ($allowances->isEmpty()) {
                return $this->errorResponse(
                    'No service allowances found for this period',
                    404
                );
            }

            $summary = [
                'period' => \Carbon\Carbon::create($year, $month, 1)->format('F Y'),
                'total_members' => $allowances->count(),
                'total_received' => $allowances->sum('received_amount'),
                'total_paid_for_installments' => $allowances->sum('installment_paid'),
                'total_remaining_for_members' => $allowances->sum('remaining_amount'),
                'processed_count' => $allowances->where('status', 'processed')->count(),
                'pending_count' => $allowances->where('status', 'pending')->count(),
            ];

            return $this->successResponse(
                $summary,
                'Period summary retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve period summary: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get member's service allowance history
     */
    public function memberHistory(Request $request, int $userId): JsonResponse
    {
        try {
            $user = auth()->user();

            // Access Control
            if ($user->isMember() && $user->id != $userId) {
                return $this->errorResponse('Access denied', 403);
            }

            $member = User::findOrFail($userId);

            $year = $request->get('year', now()->year);

            $allowances = ServiceAllowance::where('user_id', $userId)
                ->byYear($year)
                ->orderBy('period_month', 'desc')
                ->get();

            $history = [
                'user' => $member->only(['id', 'full_name', 'employee_id']),
                'year' => $year,
                'total_received' => ServiceAllowance::getMemberTotalForYear($userId, $year),
                'total_remaining' => ServiceAllowance::getMemberTotalRemainingForYear($userId, $year),
                'allowances' => $allowances->map(function($allowance) {
                    return [
                        'id' => $allowance->id,
                        'period' => $allowance->period_display,
                        'received_amount' => $allowance->received_amount,
                        'installment_paid' => $allowance->installment_paid,
                        'remaining_amount' => $allowance->remaining_amount,
                        'status' => $allowance->status,
                        'status_name' => $allowance->status_name,
                        'payment_date' => $allowance->payment_date?->format('Y-m-d'),
                    ];
                }),
            ];

            return $this->successResponse(
                $history,
                'Member history retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Member not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve member history: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * ✅ IMPROVED: Download template Excel dengan guidelines lengkap
     * 
     * Replace method downloadTemplate() in ServiceAllowanceController
     */
    public function downloadTemplate(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            
            // ==================== SHEET 1: TEMPLATE ====================
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Template Jasa Pelayanan');
            
            // ==================== HEADER ====================
            $sheet->setCellValue('A1', 'TEMPLATE IMPORT JASA PELAYANAN');
            $sheet->mergeCells('A1:F1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('4472C4');
            $sheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getRowDimension('1')->setRowHeight(30);
            
            // ==================== INSTRUCTIONS ====================
            $sheet->setCellValue('A3', '📋 PETUNJUK PENGISIAN:');
            $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle('A3')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FFD966');
            
            $instructions = [
                ['No', 'Instruksi'],
                ['1', 'Isi data mulai dari baris 14 (hapus contoh data terlebih dahulu)'],
                ['2', 'ID Member: Lihat sheet "Daftar Member" untuk ID yang valid'],
                ['3', 'Nama Member: Isi nama untuk referensi (tidak wajib, sistem akan ambil dari database)'],
                ['4', 'Periode: PENTING! Format yang diterima:'],
                ['', '   • Format standar: 01/2026 (bulan 2 digit/tahun 4 digit)'],
                ['', '   • Format alternatif: 1/2026 (tanpa leading zero)'],
                ['', '   • Format ISO: 2026-01'],
                ['', '   ⚠️ TIPS: Jika Excel auto-convert jadi tanggal, itu tidak masalah!'],
                ['5', 'Nominal: Angka tanpa format (contoh: 500000 untuk Rp 500.000)'],
                ['', '   • Jangan pakai titik, koma, atau "Rp"'],
                ['', '   • Hanya angka: 500000, 750000, 1000000'],
                ['6', 'Catatan: Opsional, bisa dikosongkan'],
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
            
            // Border untuk instruksi
            $sheet->getStyle('A4:B' . ($row - 1))->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);
            
            // ==================== FORMAT EXAMPLES ====================
            $sheet->setCellValue('D3', '✅ CONTOH FORMAT PERIODE YANG BENAR:');
            $sheet->getStyle('D3')->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle('D3')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('C6E0B4');
            $sheet->mergeCells('D3:F3');
            
            $periodExamples = [
                ['Format', 'Contoh', 'Keterangan'],
                ['MM/YYYY', '01/2026', 'Format standar (2 digit bulan)'],
                ['M/YYYY', '1/2026', 'Tanpa leading zero (OK)'],
                ['YYYY-MM', '2026-01', 'Format ISO (OK)'],
                ['YYYY/MM', '2026/01', 'Alternatif (OK)'],
                ['Excel Date', 'Jan 2026', 'Jika Excel convert (OK)'],
            ];
            
            $row = 4;
            foreach ($periodExamples as $example) {
                $sheet->setCellValue('D' . $row, $example[0]);
                $sheet->setCellValue('E' . $row, $example[1]);
                $sheet->setCellValue('F' . $row, $example[2]);
                
                if ($example[0] === 'Format') {
                    $sheet->getStyle('D' . $row . ':F' . $row)->getFont()->setBold(true);
                }
                
                $row++;
            }
            
            // Border untuk contoh
            $sheet->getStyle('D4:F' . ($row - 1))->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);
            
            // ==================== WRONG EXAMPLES ====================
            $wrongRow = $row + 1;
            $sheet->setCellValue('D' . $wrongRow, '❌ CONTOH FORMAT YANG SALAH:');
            $sheet->getStyle('D' . $wrongRow)->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle('D' . $wrongRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('F4B084');
            $sheet->mergeCells('D' . $wrongRow . ':F' . $wrongRow);
            
            $wrongExamples = [
                ['Contoh Salah', 'Alasan'],
                ['13/2026', 'Bulan tidak valid (harus 1-12)'],
                ['01-2026', 'Separator salah (gunakan /)'],
                ['2026', 'Hanya tahun, bulan hilang'],
                ['Januari 2026', 'Teks Indonesia (gunakan angka)'],
            ];
            
            $wrongRow++;
            foreach ($wrongExamples as $wrong) {
                $sheet->setCellValue('D' . $wrongRow, $wrong[0]);
                $sheet->setCellValue('E' . $wrongRow, $wrong[1]);
                
                if ($wrong[0] === 'Contoh Salah') {
                    $sheet->getStyle('D' . $wrongRow . ':E' . $wrongRow)->getFont()->setBold(true);
                } else {
                    $sheet->getStyle('D' . $wrongRow . ':E' . $wrongRow)
                        ->getFont()->getColor()->setRGB('FF0000');
                }
                
                $wrongRow++;
            }
            
            $sheet->mergeCells('E' . ($wrongRow - count($wrongExamples) + 1) . ':F' . ($wrongRow - 1));
            
            // Border untuk wrong examples
            $sheet->getStyle('D' . ($row + 1) . ':E' . ($wrongRow - 1))->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);
            
            // ==================== COLUMN HEADERS ====================
            $headerRow = 12;
            $sheet->setCellValue('A' . $headerRow, '📝 MULAI ISI DATA DI BAWAH INI:');
            $sheet->mergeCells('A' . $headerRow . ':F' . $headerRow);
            $sheet->getStyle('A' . $headerRow)->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle('A' . $headerRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A' . $headerRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('92D050');
            
            $headers = [
                'ID Member',
                'Nama Member',
                'Periode (MM/YYYY)',
                'Nominal Jasa Pelayanan',
                'Catatan',
                'Status'
            ];
            
            $headerRow = 13;
            $column = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($column . $headerRow, $header);
                $sheet->getStyle($column . $headerRow)->getFont()->setBold(true);
                $sheet->getStyle($column . $headerRow)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('4472C4');
                $sheet->getStyle($column . $headerRow)->getFont()->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($column . $headerRow)->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $column++;
            }
            
            // ==================== SAMPLE DATA ====================
            $sampleData = [
                [1, 'John Doe', '01/2026', 500000, 'Jasa pelayanan Januari 2026', ''],
                [2, 'Jane Smith', '1/2026', 750000, 'Format tanpa leading zero juga OK', ''],
                [3, 'Bob Wilson', '2026-01', 600000, 'Format ISO juga OK', ''],
            ];
            
            $row = 14;
            foreach ($sampleData as $data) {
                $column = 'A';
                foreach ($data as $value) {
                    $sheet->setCellValue($column . $row, $value);
                    $column++;
                }
                // Add note
                $sheet->getStyle('A' . $row . ':F' . $row)
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('E7E6E6');
                $row++;
            }
            
            // ==================== FORMATTING ====================
            // Set column widths
            $sheet->getColumnDimension('A')->setWidth(12);
            $sheet->getColumnDimension('B')->setWidth(25);
            $sheet->getColumnDimension('C')->setWidth(20);
            $sheet->getColumnDimension('D')->setWidth(25);
            $sheet->getColumnDimension('E')->setWidth(35);
            $sheet->getColumnDimension('F')->setWidth(15);
            
            // Add borders to data area
            $sheet->getStyle('A' . $headerRow . ':F' . ($row - 1))->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);
            
            // ==================== SHEET 2: DAFTAR MEMBER ====================
            $memberSheet = $spreadsheet->createSheet();
            $memberSheet->setTitle('Daftar Member');
            
            // Header
            $memberSheet->setCellValue('A1', 'DAFTAR MEMBER AKTIF');
            $memberSheet->mergeCells('A1:D1');
            $memberSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $memberSheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $memberSheet->getStyle('A1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('70AD47');
            $memberSheet->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');
            
            // Column headers
            $memberHeaders = ['ID', 'NIP/Employee ID', 'Nama Lengkap', 'Status'];
            $column = 'A';
            foreach ($memberHeaders as $header) {
                $memberSheet->setCellValue($column . '2', $header);
                $memberSheet->getStyle($column . '2')->getFont()->setBold(true);
                $memberSheet->getStyle($column . '2')->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('A9D08E');
                $column++;
            }
            
            // Get active members
            $members = \App\Models\User::members()
                ->where('status', 'active')
                ->orderBy('employee_id')
                ->get(['id', 'employee_id', 'full_name', 'status']);
            
            $row = 3;
            foreach ($members as $member) {
                $memberSheet->setCellValue('A' . $row, $member->id);
                $memberSheet->setCellValue('B' . $row, $member->employee_id);
                $memberSheet->setCellValue('C' . $row, $member->full_name);
                $memberSheet->setCellValue('D' . $row, $member->status);
                
                // Zebra striping
                if ($row % 2 == 0) {
                    $memberSheet->getStyle('A' . $row . ':D' . $row)
                        ->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('F2F2F2');
                }
                
                $row++;
            }
            
            // Borders
            $memberSheet->getStyle('A2:D' . ($row - 1))->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);
            
            // Column widths
            $memberSheet->getColumnDimension('A')->setWidth(10);
            $memberSheet->getColumnDimension('B')->setWidth(20);
            $memberSheet->getColumnDimension('C')->setWidth(35);
            $memberSheet->getColumnDimension('D')->setWidth(15);
            
            // ==================== SHEET 3: TIPS & TROUBLESHOOTING ====================
            $tipsSheet = $spreadsheet->createSheet();
            $tipsSheet->setTitle('Tips & Troubleshooting');
            
            $tipsSheet->setCellValue('A1', '💡 TIPS & TROUBLESHOOTING');
            $tipsSheet->mergeCells('A1:B1');
            $tipsSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $tipsSheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $tipsSheet->getStyle('A1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('FFC000');
            
            $tips = [
                ['Masalah', 'Solusi'],
                ['Excel mengubah periode jadi tanggal', 'Tidak masalah! Sistem bisa membaca format Excel'],
                ['Format periode ditolak', 'Coba format: 01/2026, 1/2026, atau 2026-01'],
                ['ID Member tidak ditemukan', 'Cek sheet "Daftar Member" untuk ID yang benar'],
                ['Nominal ditolak', 'Hapus semua format: titik, koma, Rp. Hanya angka!'],
                ['Data sudah ada', 'Member ini sudah punya jasa pelayanan di periode tersebut'],
                ['Member tidak aktif', 'Hanya member dengan status "active" yang bisa diimport'],
                ['', ''],
                ['📌 TIPS EXCEL:', ''],
                ['Agar Excel tidak auto-format periode:', '1. Pilih kolom Periode'],
                ['', '2. Klik kanan > Format Cells'],
                ['', '3. Pilih "Text"'],
                ['', '4. Ketik: 01/2026'],
                ['', ''],
                ['Atau prefix dengan apostrophe:', "Ketik: '01/2026"],
            ];
            
            $row = 2;
            foreach ($tips as $tip) {
                $tipsSheet->setCellValue('A' . $row, $tip[0]);
                $tipsSheet->setCellValue('B' . $row, $tip[1]);
                
                if ($tip[0] === 'Masalah') {
                    $tipsSheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);
                }
                
                if (strpos($tip[0], '📌') !== false) {
                    $tipsSheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);
                    $tipsSheet->getStyle('A' . $row . ':B' . $row)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('FFE699');
                }
                
                $row++;
            }
            
            $tipsSheet->getStyle('A2:B' . ($row - 1))->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);
            
            $tipsSheet->getColumnDimension('A')->setWidth(40);
            $tipsSheet->getColumnDimension('B')->setWidth(50);
            
            // Set active sheet back to template
            $spreadsheet->setActiveSheetIndex(0);
            
            // ==================== SAVE FILE ====================
            $filename = 'Template_Jasa_Pelayanan_' . date('Y-m-d') . '.xlsx';
            $filepath = storage_path('app/temp/' . $filename);
            
            if (!file_exists(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }
            
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filepath);
            
            return response()->download($filepath, $filename)->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            \Log::error('Template download error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            abort(500, 'Gagal membuat template: ' . $e->getMessage());
        }
    }

    /**
     * Import jasa pelayanan dari Excel
     * 
     * Expected format:
     * - Column A: ID Member
     * - Column B: Nama Member (optional, for reference)
     * - Column C: Periode (MM/YYYY)
     * - Column D: Nominal
     * - Column E: Catatan (optional)
     */
    public function importExcel(Request $request): JsonResponse
    {
        try {
            // Validation
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls|max:5120', // Max 5MB
            ]);
            
            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            
            // Get highest row
            $highestRow = $sheet->getHighestRow();
            
            if ($highestRow < 8) {
                return $this->errorResponse('File Excel tidak memiliki data. Minimal harus ada 1 baris data.', 400);
            }
            
            $errors = [];
            $validData = [];
            $processedBy = auth()->id();
            
            // Process rows (starting from row 8, skipping header and samples)
            for ($row = 8; $row <= $highestRow; $row++) {
                $userId = $sheet->getCell('A' . $row)->getValue();
                $period = $sheet->getCell('C' . $row)->getValue();
                $amount = $sheet->getCell('D' . $row)->getValue();
                $notes = $sheet->getCell('E' . $row)->getValue();
                
                // Skip empty rows
                if (empty($userId) && empty($period) && empty($amount)) {
                    continue;
                }
                
                $rowData = [
                    'row' => $row,
                    'user_id' => $userId,
                    'period' => $period,
                    'amount' => $amount,
                    'notes' => $notes,
                ];
                
                // Validate row
                $validation = $this->validateImportRow($rowData, $row);
                
                if (!$validation['valid']) {
                    $errors[] = $validation['errors'];
                } else {
                    $validData[] = $validation['data'];
                }
            }
            
            // If there are errors, return them
            if (!empty($errors)) {
                return $this->errorResponse(
                    'Validasi gagal. Perbaiki error berikut:',
                    422,
                    ['errors' => $errors]
                );
            }
            
            // If no valid data
            if (empty($validData)) {
                return $this->errorResponse('Tidak ada data valid untuk diimport', 400);
            }
            
            // Process all valid data
            DB::beginTransaction();
            
            try {
                $results = [
                    'success' => [],
                    'failed' => [],
                ];
                
                foreach ($validData as $data) {
                    try {
                        $member = User::find($data['user_id']);
                        
                        $result = ServiceAllowance::processForMember(
                            $member,
                            $data['period_month'],
                            $data['period_year'],
                            $data['received_amount'],
                            $processedBy,
                            $data['notes']
                        );
                        
                        $results['success'][] = [
                            'row' => $data['row'],
                            'member' => $member->full_name,
                            'period' => $data['period_month'] . '/' . $data['period_year'],
                            'amount' => $data['received_amount'],
                            'summary' => $result['summary']['message'],
                        ];
                        
                    } catch (\Exception $e) {
                        $results['failed'][] = [
                            'row' => $data['row'],
                            'user_id' => $data['user_id'],
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
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse('Validasi file gagal', 422, $e->errors());
        } catch (\Exception $e) {
            \Log::error('Import error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            return $this->errorResponse(
                'Gagal import: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * ✅ FLEXIBLE: Validate single import row with better period parsing
     * 
     * This replaces the validateImportRow() method in ServiceAllowanceController
     */
    private function validateImportRow(array $rowData, int $rowNumber): array
    {
        $errors = [];
        
        // Validate user_id
        if (empty($rowData['user_id'])) {
            $errors[] = "ID Member tidak boleh kosong";
        } else {
            $user = \App\Models\User::find($rowData['user_id']);
            if (!$user) {
                $errors[] = "ID Member tidak ditemukan: {$rowData['user_id']}";
            } elseif (!$user->isMember()) {
                $errors[] = "User {$rowData['user_id']} bukan member";
            } elseif ($user->status !== 'active') {
                $errors[] = "Member {$user->full_name} tidak aktif";
            }
        }
        
        // ✅ FLEXIBLE: Validate period with multiple format support
        $month = null;
        $year = null;
        
        if (empty($rowData['period'])) {
            $errors[] = "Periode tidak boleh kosong";
        } else {
            $period = trim($rowData['period']);
            
            // ✅ Try multiple formats
            $parsed = $this->parsePeriod($period);
            
            if ($parsed === false) {
                $errors[] = "Format periode tidak valid. Gunakan format: MM/YYYY, M/YYYY, atau YYYY-MM (contoh: 01/2026, 1/2026, atau 2026-01)";
            } else {
                $month = $parsed['month'];
                $year = $parsed['year'];
                
                // Validate range
                if ($month < 1 || $month > 12) {
                    $errors[] = "Bulan harus antara 1-12";
                }
                
                if ($year < 2020 || $year > 2100) {
                    $errors[] = "Tahun harus antara 2020-2100";
                }
                
                // Check if already exists
                if (!empty($rowData['user_id']) && isset($month) && isset($year)) {
                    $existing = \App\Models\ServiceAllowance::where('user_id', $rowData['user_id'])
                        ->where('period_month', $month)
                        ->where('period_year', $year)
                        ->first();
                    
                    if ($existing) {
                        $errors[] = "Jasa pelayanan untuk member ini di periode {$month}/{$year} sudah ada";
                    }
                }
            }
        }
        
        // Validate amount
        if (empty($rowData['amount']) && $rowData['amount'] !== 0 && $rowData['amount'] !== '0') {
            $errors[] = "Nominal tidak boleh kosong";
        } else {
            // Clean amount (remove formatting if any)
            $amount = str_replace(['.', ',', ' ', 'Rp'], '', $rowData['amount']);
            if (!is_numeric($amount)) {
                $errors[] = "Nominal harus berupa angka";
            } elseif ($amount < 0) {
                $errors[] = "Nominal tidak boleh negatif";
            }
        }
        
        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => [
                    'row' => $rowNumber,
                    'messages' => $errors,
                ],
            ];
        }
        
        // Return cleaned data
        $amount = str_replace(['.', ',', ' ', 'Rp'], '', $rowData['amount']);
        
        return [
            'valid' => true,
            'data' => [
                'row' => $rowNumber,
                'user_id' => $rowData['user_id'],
                'period_month' => (int) $month,
                'period_year' => (int) $year,
                'received_amount' => (float) $amount,
                'notes' => $rowData['notes'],
            ],
        ];
    }

    /**
     * ✅ NEW: Parse period with multiple format support
     * 
     * Supported formats:
     * - MM/YYYY (e.g., 01/2026)
     * - M/YYYY (e.g., 1/2026)
     * - YYYY-MM (e.g., 2026-01)
     * - YYYY/MM (e.g., 2026/01)
     * - Excel date serial number
     * 
     * @param mixed $period
     * @return array|false ['month' => int, 'year' => int] or false if invalid
     */
    private function parsePeriod($period)
    {
        // Remove extra spaces
        $period = trim($period);
        
        // Case 1: Empty
        if (empty($period)) {
            return false;
        }
        
        // Case 2: Excel date serial number (e.g., 46110 for Jan 2026)
        if (is_numeric($period) && $period > 40000) {
            try {
                // Convert Excel serial to date
                $unixDate = ($period - 25569) * 86400;
                $date = new \DateTime('@' . $unixDate);
                
                return [
                    'month' => (int) $date->format('n'),
                    'year' => (int) $date->format('Y'),
                ];
            } catch (\Exception $e) {
                // Continue to other formats
            }
        }
        
        // Case 3: Format MM/YYYY or M/YYYY (e.g., "01/2026" or "1/2026")
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $period, $matches)) {
            return [
                'month' => (int) $matches[1],
                'year' => (int) $matches[2],
            ];
        }
        
        // Case 4: Format YYYY-MM (e.g., "2026-01")
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $period, $matches)) {
            return [
                'month' => (int) $matches[2],
                'year' => (int) $matches[1],
            ];
        }
        
        // Case 5: Format YYYY/MM (e.g., "2026/01")
        if (preg_match('/^(\d{4})\/(\d{1,2})$/', $period, $matches)) {
            return [
                'month' => (int) $matches[2],
                'year' => (int) $matches[1],
            ];
        }
        
        // Case 6: Format "Jan 2026", "January 2026", etc.
        try {
            $date = new \DateTime($period);
            return [
                'month' => (int) $date->format('n'),
                'year' => (int) $date->format('Y'),
            ];
        } catch (\Exception $e) {
            // Invalid format
        }
        
        return false;
    }

    /**
     * Export jasa pelayanan ke Excel
     * 
     * Features:
     * - Export by period or all data
     * - Summary section
     * - Member details
     * - Formatting & styling
     */
    public function exportExcel(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            // Get filters
            $month = $request->get('month');
            $year = $request->get('year', date('Y'));
            
            $query = ServiceAllowance::with(['user:id,full_name,employee_id']);
            
            if ($month && $year) {
                $query->byPeriod($month, $year);
                $periodLabel = \Carbon\Carbon::create($year, $month, 1)->format('F Y');
            } elseif ($year) {
                $query->byYear($year);
                $periodLabel = "Tahun $year";
            } else {
                $periodLabel = "Semua Data";
            }
            
            $allowances = $query->orderBy('period_year', 'desc')
                ->orderBy('period_month', 'desc')
                ->orderBy('user_id')
                ->get();
            
            if ($allowances->isEmpty()) {
                abort(404, 'Tidak ada data untuk diekspor');
            }
            
            // Create spreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Jasa Pelayanan');
            
            // Header
            $sheet->setCellValue('A1', 'LAPORAN JASA PELAYANAN');
            $sheet->mergeCells('A1:H1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            $sheet->setCellValue('A2', 'Periode: ' . $periodLabel);
            $sheet->mergeCells('A2:H2');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            $sheet->setCellValue('A3', 'Dicetak: ' . now()->format('d F Y H:i'));
            $sheet->mergeCells('A3:H3');
            $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            // Summary section
            $totalReceived = $allowances->sum('received_amount');
            $totalPaidForInstallments = $allowances->sum('installment_paid');
            $totalRemaining = $allowances->sum('remaining_amount');
            
            $sheet->setCellValue('A5', 'RINGKASAN:');
            $sheet->getStyle('A5')->getFont()->setBold(true);
            
            $sheet->setCellValue('A6', 'Total Member:');
            $sheet->setCellValue('B6', $allowances->count());
            
            $sheet->setCellValue('A7', 'Total Diterima dari RS:');
            $sheet->setCellValue('B7', $totalReceived);
            $sheet->getStyle('B7')->getNumberFormat()->setFormatCode('#,##0');
            
            $sheet->setCellValue('A8', 'Total untuk Cicilan:');
            $sheet->setCellValue('B8', $totalPaidForInstallments);
            $sheet->getStyle('B8')->getNumberFormat()->setFormatCode('#,##0');
            
            $sheet->setCellValue('A9', 'Total Sisa untuk Member:');
            $sheet->setCellValue('B9', $totalRemaining);
            $sheet->getStyle('B9')->getNumberFormat()->setFormatCode('#,##0');
            
            // Column headers
            $headers = ['No', 'NIP', 'Nama Member', 'Periode', 'Diterima', 'Cicilan', 'Sisa', 'Status'];
            $column = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($column . '11', $header);
                $sheet->getStyle($column . '11')->getFont()->setBold(true);
                $sheet->getStyle($column . '11')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('4472C4');
                $sheet->getStyle($column . '11')->getFont()->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($column . '11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $column++;
            }
            
            // Data rows
            $row = 12;
            $no = 1;
            foreach ($allowances as $allowance) {
                $sheet->setCellValue('A' . $row, $no++);
                $sheet->setCellValue('B' . $row, $allowance->user->employee_id ?? 'N/A');
                $sheet->setCellValue('C' . $row, $allowance->user->full_name ?? 'Unknown');
                $sheet->setCellValue('D' . $row, $allowance->period_display);
                $sheet->setCellValue('E' . $row, $allowance->received_amount);
                $sheet->setCellValue('F' . $row, $allowance->installment_paid);
                $sheet->setCellValue('G' . $row, $allowance->remaining_amount);
                $sheet->setCellValue('H' . $row, $allowance->status_name);
                
                // Number formatting
                $sheet->getStyle('E' . $row)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0');
                
                // Alignment
                $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('H' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                
                $row++;
            }
            
            // Set column widths
            $sheet->getColumnDimension('A')->setWidth(6);
            $sheet->getColumnDimension('B')->setWidth(15);
            $sheet->getColumnDimension('C')->setWidth(30);
            $sheet->getColumnDimension('D')->setWidth(15);
            $sheet->getColumnDimension('E')->setWidth(15);
            $sheet->getColumnDimension('F')->setWidth(15);
            $sheet->getColumnDimension('G')->setWidth(15);
            $sheet->getColumnDimension('H')->setWidth(15);
            
            // Add borders
            $lastRow = $row - 1;
            $sheet->getStyle('A11:H' . $lastRow)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);
            
            // Save file
            $filename = 'Jasa_Pelayanan_' . str_replace(' ', '_', $periodLabel) . '_' . date('Y-m-d') . '.xlsx';
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