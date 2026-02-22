<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Traits\ApiResponse;

class SettingController extends Controller
{
    use ApiResponse;

    /**
     * Get settings by group
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $group = $request->query('group');

            $query = Setting::query();
            if ($group) {
                $query->where('group', $group);
            }

            $settings = $query->get();

            // Format response
            $result = [];
            foreach ($settings as $setting) {
                if (!isset($result[$setting->group])) {
                    $result[$setting->group] = [];
                }
                $result[$setting->group][$setting->key] = $setting->payload;
            }

            // If a specific group is requested, return just that group's object directly
            if ($group) {
                $groupData = $result[$group] ?? new \stdClass();
                return $this->successResponse($groupData, 'Settings retrieved successfully');
            }

            return $this->successResponse($result, 'Settings retrieved successfully');
        }
        catch (\Exception $e) {
            return $this->errorResponse('Failed to retrieve settings: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update settings for a specific group
     */
    public function update(Request $request, string $group): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user->isAdmin()) {
                return $this->errorResponse('Hanya admin yang dapat mengubah pengaturan', 403);
            }

            $data = $request->all();

            foreach ($data as $key => $value) {
                Setting::updateOrCreate(
                ['group' => $group, 'key' => $key],
                ['payload' => $value, 'type' => gettype($value)]
                );
            }

            return $this->successResponse(null, 'Pengaturan ' . ucfirst($group) . ' berhasil disimpan');
        }
        catch (\Exception $e) {
            return $this->errorResponse('Failed to update settings: ' . $e->getMessage(), 500);
        }
    }
}