<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * Register a new user
     *
     * @param RegisterRequest $request
     * @return JsonResponse
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            // Create user
            $user = User::create([
                'full_name' => $request->full_name,
                'employee_id' => $request->employee_id,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'anggota', // Default role
                'is_active' => true,
                'phone_number' => $request->phone_number,
                'address' => $request->address,
                'work_unit' => $request->work_unit,
                'position' => $request->position,
                'joined_at' => now(),
            ]);

            // Generate token
            $token = JWTAuth::fromUser($user);

            return $this->successResponse([
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'employee_id' => $user->employee_id,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60 // in seconds
            ], 'Registration successful', 201);

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Registration failed: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Login user
     *
     * @param LoginRequest $request
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        try {
            // Attempt to authenticate
            if (!$token = JWTAuth::attempt($credentials)) {
                return $this->errorResponse('Invalid credentials', 401);
            }

            // Get authenticated user
            $user = auth()->user();

            // Check if user is active
            if (!$user->isActive()) {
                return $this->errorResponse('Your account is inactive', 403);
            }

            return $this->successResponse([
                'user' => [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'employee_id' => $user->employee_id,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_active' => $user->is_active,
                ],
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60 // in seconds
            ], 'Login successful');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Login failed: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get authenticated user
     *
     * @return JsonResponse
     */
    public function me(): JsonResponse
    {
        try {
            $user = auth()->user();

            return $this->successResponse([
                'id' => $user->id,
                'full_name' => $user->full_name,
                'employee_id' => $user->employee_id,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'phone_number' => $user->phone_number,
                'address' => $user->address,
                'work_unit' => $user->work_unit,
                'position' => $user->position,
                'joined_at' => $user->joined_at,
                'created_at' => $user->created_at,
            ], 'User data retrieved');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to get user data: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Logout user
     *
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());

            return $this->successResponse(null, 'Logout successful');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Logout failed: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Refresh token
     *
     * @return JsonResponse
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());

            return $this->successResponse([
                'token' => $newToken,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60
            ], 'Token refreshed successfully');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Token refresh failed: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Change password
     *
     * @param ChangePasswordRequest $request
     * @return JsonResponse
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // Check current password
            if (!Hash::check($request->current_password, $user->password)) {
                return $this->errorResponse('Current password is incorrect', 400);
            }

            // Update password
            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            return $this->successResponse(null, 'Password changed successfully');

        } catch (\Exception $e) {
            return $this->errorResponse(
                'Password change failed: ' . $e->getMessage(),
                500
            );
        }
    }
}