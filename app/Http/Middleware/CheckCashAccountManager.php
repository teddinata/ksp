<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\CashAccount;
use Symfony\Component\HttpFoundation\Response;

class CheckCashAccountManager
{
    public function handle(Request $request, Closure $next, ?string $cashAccountId = null): Response
    {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }
        
        // Admin bisa akses semua kas
        if ($user->isAdmin()) {
            return $next($request);
        }
        
        // Jika role manager, cek apakah dia manager kas ini
        if ($user->isManager()) {
            // Jika tidak specify cash account, allow (untuk list/index)
            if (!$cashAccountId) {
                return $next($request);
            }
            
            // Cek apakah user adalah manager dari kas ini
            if (!$user->isCashAccountManager($cashAccountId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not the manager of this cash account',
                ], 403);
            }
            
            return $next($request);
        }
        
        // Member tidak boleh akses
        return response()->json([
            'success' => false,
            'message' => 'Access denied. Only admin and cash account managers allowed.',
        ], 403);
    }
}