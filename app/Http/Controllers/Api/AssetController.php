<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssetRequest;
use App\Models\Asset;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of assets.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Asset::query();

            // Filter by category
            if ($request->has('category')) {
                $query->byCategory($request->category);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('asset_code', 'like', "%{$search}%")
                      ->orWhere('asset_name', 'like', "%{$search}%")
                      ->orWhere('location', 'like', "%{$search}%");
                });
            }

            // Sort
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($request->has('all') && $request->boolean('all')) {
                $assets = $query->get();
                
                $assets->each(function($asset) {
                    $asset->category_name = $asset->category_name;
                    $asset->status_name = $asset->status_name;
                });

                return $this->successResponse($assets, 'Assets retrieved successfully');
            } else {
                $assets = $query->paginate($perPage);
                
                $assets->getCollection()->each(function($asset) {
                    $asset->category_name = $asset->category_name;
                    $asset->status_name = $asset->status_name;
                });

                return $this->paginatedResponse($assets, 'Assets retrieved successfully');
            }

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve assets: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Store a newly created asset.
     *
     * @param AssetRequest $request
     * @return JsonResponse
     */
    public function store(AssetRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            
            // Generate asset code
            $data['asset_code'] = Asset::generateAssetCode($data['category']);
            
            // Calculate depreciation per month
            $asset = new Asset($data);
            $data['depreciation_per_month'] = $asset->calculateDepreciationPerMonth();
            
            // Initial book value = acquisition cost
            $data['book_value'] = $data['acquisition_cost'];
            $data['accumulated_depreciation'] = 0;

            $asset = Asset::create($data);

            // Log activity
            \App\Models\ActivityLog::createLog([
                'activity' => 'create',
                'module' => 'assets',
                'description' => auth()->user()->full_name . ' menambahkan aset ' . $asset->asset_name,
            ]);

            $asset->category_name = $asset->category_name;
            $asset->status_name = $asset->status_name;

            return $this->successResponse(
                $asset,
                'Asset created successfully',
                201
            );

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to create asset: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Display the specified asset.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $asset = Asset::findOrFail($id);

            $asset->category_name = $asset->category_name;
            $asset->status_name = $asset->status_name;

            return $this->successResponse(
                $asset,
                'Asset retrieved successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Asset not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve asset: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update the specified asset.
     *
     * @param AssetRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(AssetRequest $request, int $id): JsonResponse
    {
        try {
            $asset = Asset::findOrFail($id);

            $data = $request->validated();
            
            // Recalculate depreciation if relevant fields changed
            if (isset($data['acquisition_cost']) || isset($data['useful_life_months']) || isset($data['residual_value'])) {
                $tempAsset = new Asset(array_merge($asset->toArray(), $data));
                $data['depreciation_per_month'] = $tempAsset->calculateDepreciationPerMonth();
                
                // Recalculate book value
                $data['book_value'] = $data['acquisition_cost'] - $asset->accumulated_depreciation;
            }

            $asset->update($data);

            // Log activity
            \App\Models\ActivityLog::createLog([
                'activity' => 'update',
                'module' => 'assets',
                'description' => auth()->user()->full_name . ' mengubah aset ' . $asset->asset_name,
            ]);

            $asset->category_name = $asset->category_name;
            $asset->status_name = $asset->status_name;

            return $this->successResponse(
                $asset,
                'Asset updated successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Asset not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to update asset: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Remove the specified asset.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $asset = Asset::findOrFail($id);

            $assetName = $asset->asset_name;
            $asset->delete();

            // Log activity
            \App\Models\ActivityLog::createLog([
                'activity' => 'delete',
                'module' => 'assets',
                'description' => auth()->user()->full_name . ' menghapus aset ' . $assetName,
            ]);

            return $this->successResponse(
                null,
                'Asset deleted successfully'
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Asset not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to delete asset: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Calculate depreciation for single asset.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function calculateDepreciation(int $id): JsonResponse
    {
        try {
            $asset = Asset::findOrFail($id);

            if ($asset->category == 'land') {
                return $this->errorResponse('Land assets do not depreciate', 400);
            }

            $oldAccumulated = $asset->accumulated_depreciation;
            $asset->calculateDepreciation();
            $newDepreciation = $asset->accumulated_depreciation - $oldAccumulated;

            return $this->successResponse([
                'asset' => $asset,
                'depreciation_calculated' => $newDepreciation,
                'new_accumulated' => $asset->accumulated_depreciation,
                'new_book_value' => $asset->book_value,
            ], 'Depreciation calculated successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Asset not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to calculate depreciation: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Calculate depreciation for all assets.
     *
     * @return JsonResponse
     */
    public function calculateAllDepreciation(): JsonResponse
    {
        try {
            $result = Asset::calculateAllDepreciation();

            return $this->successResponse([
                'assets_processed' => $result['count'],
                'total_depreciation' => $result['total_depreciation'],
                'details' => collect($result['assets'])->map(function($item) {
                    return [
                        'asset_code' => $item['asset']->asset_code,
                        'asset_name' => $item['asset']->asset_name,
                        'depreciation_amount' => $item['depreciation_amount'],
                        'new_book_value' => $item['asset']->book_value,
                    ];
                }),
            ], 'Depreciation calculated for all assets');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to calculate depreciation: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get depreciation schedule for asset.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function depreciationSchedule(int $id): JsonResponse
    {
        try {
            $asset = Asset::findOrFail($id);

            if ($asset->category == 'land') {
                return $this->errorResponse('Land assets do not depreciate', 400);
            }

            $schedule = $asset->getDepreciationSchedule();

            return $this->successResponse([
                'asset' => $asset->only(['asset_code', 'asset_name', 'acquisition_cost', 'useful_life_months']),
                'schedule' => $schedule,
            ], 'Depreciation schedule retrieved successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Asset not found', 404);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve schedule: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get assets summary.
     *
     * @return JsonResponse
     */
    public function summary(): JsonResponse
    {
        try {
            $summary = [
                'total_assets' => Asset::count(),
                'active_assets' => Asset::active()->count(),
                'total_acquisition_cost' => Asset::sum('acquisition_cost'),
                'total_accumulated_depreciation' => Asset::sum('accumulated_depreciation'),
                'total_book_value' => Asset::sum('book_value'),
                'by_category' => [
                    'land' => Asset::byCategory('land')->sum('book_value'),
                    'building' => Asset::byCategory('building')->sum('book_value'),
                    'vehicle' => Asset::byCategory('vehicle')->sum('book_value'),
                    'equipment' => Asset::byCategory('equipment')->sum('book_value'),
                    'inventory' => Asset::byCategory('inventory')->sum('book_value'),
                ],
            ];

            return $this->successResponse($summary, 'Assets summary retrieved successfully');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve summary: ' . $e->getMessage(),
                500
            );
        }
    }
}