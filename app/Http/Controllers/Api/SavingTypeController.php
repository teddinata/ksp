<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\SavingType;
use App\Http\Requests\SavingTypeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavingTypeController extends Controller
{
    /**
     * Display a listing of saving types.
     * 
     * GET /api/saving-types
     */
    public function index(Request $request)
    {
        try {
            $query = SavingType::query();
            
            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->is_active);
            }
            
            // Filter by mandatory status
            if ($request->has('is_mandatory')) {
                $query->where('is_mandatory', $request->is_mandatory);
            }
            
            // Filter by withdrawable status
            if ($request->has('is_withdrawable')) {
                $query->where('is_withdrawable', $request->is_withdrawable);
            }
            
            // Search by name or code
            if ($request->has('search')) {
                $query->where(function($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                      ->orWhere('code', 'like', '%' . $request->search . '%');
                });
            }
            
            // Include statistics if requested
            $withStats = $request->get('with_stats', false);
            
            $savingTypes = $query->ordered()->get();
            
            if ($withStats) {
                $savingTypes = $savingTypes->map(function($type) {
                    $type->statistics = $type->getStatistics();
                    return $type;
                });
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Data jenis simpanan berhasil diambil',
                'data' => $savingTypes
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Store a newly created saving type.
     * 
     * POST /api/saving-types
     */
    public function store(SavingTypeRequest $request)
    {
        try {
            DB::beginTransaction();
            
            $createdBy = auth()->id();
            
            $savingType = SavingType::createType($request->validated(), $createdBy);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Jenis simpanan berhasil dibuat',
                'data' => $savingType
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat jenis simpanan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified saving type.
     * 
     * GET /api/saving-types/{id}
     */
    public function show($id)
    {
        try {
            $savingType = SavingType::with(['savings', 'creator'])->findOrFail($id);
            
            $statistics = $savingType->getStatistics();
            
            return response()->json([
                'success' => true,
                'message' => 'Detail jenis simpanan',
                'data' => [
                    'saving_type' => $savingType,
                    'statistics' => $statistics
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 404);
        }
    }
    
    /**
     * Update the specified saving type.
     * 
     * PUT /api/saving-types/{id}
     */
    public function update($id, SavingTypeRequest $request)
    {
        try {
            DB::beginTransaction();
            
            $savingType = SavingType::findOrFail($id);
            
            // Prevent updating code of default types
            $defaultCodes = ['POKOK', 'WAJIB', 'SUKARELA', 'HARIRAYA'];
            if (in_array($savingType->code, $defaultCodes) && $request->code !== $savingType->code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kode jenis simpanan default tidak dapat diubah'
                ], 422);
            }
            
            $savingType->update($request->validated());
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Jenis simpanan berhasil diupdate',
                'data' => $savingType
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal update jenis simpanan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Remove the specified saving type.
     * 
     * DELETE /api/saving-types/{id}
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();
            
            $savingType = SavingType::findOrFail($id);
            
            // Prevent deleting default types
            $defaultCodes = ['POKOK', 'WAJIB', 'SUKARELA', 'HARIRAYA'];
            if (in_array($savingType->code, $defaultCodes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jenis simpanan default tidak dapat dihapus'
                ], 422);
            }
            
            // Check if being used
            $savingsCount = $savingType->savings()->count();
            if ($savingsCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Tidak dapat menghapus. Jenis simpanan ini digunakan oleh {$savingsCount} transaksi simpanan"
                ], 422);
            }
            
            $savingType->delete();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Jenis simpanan berhasil dihapus'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus jenis simpanan: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get default saving types.
     * 
     * GET /api/saving-types/defaults
     */
    public function defaults()
    {
        try {
            $defaults = SavingType::getDefaultTypes();
            
            return response()->json([
                'success' => true,
                'message' => 'Jenis simpanan default',
                'data' => $defaults
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get mandatory saving types.
     * 
     * GET /api/saving-types/mandatory
     */
    public function mandatory()
    {
        try {
            $mandatory = SavingType::getMandatoryTypes();
            
            return response()->json([
                'success' => true,
                'message' => 'Jenis simpanan wajib',
                'data' => $mandatory
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get optional saving types.
     * 
     * GET /api/saving-types/optional
     */
    public function optional()
    {
        try {
            $optional = SavingType::getOptionalTypes();
            
            return response()->json([
                'success' => true,
                'message' => 'Jenis simpanan sukarela',
                'data' => $optional
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data: ' . $e->getMessage()
            ], 500);
        }
    }
}