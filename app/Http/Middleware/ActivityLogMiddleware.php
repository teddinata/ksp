<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\ActivityLog;

class ActivityLogMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only log authenticated requests
        if (!auth()->check()) {
            return $response;
        }

        // Only log successful requests (2xx status codes)
        if (!$response->isSuccessful()) {
            return $response;
        }

        // Only log specific methods
        if (!in_array($request->method(), ['POST', 'PUT', 'DELETE'])) {
            return $response;
        }

        // Determine activity type
        $activity = $this->getActivityType($request->method());
        
        // Determine module
        $module = $this->getModule($request->path());

        // Skip if no module detected
        if (!$module) {
            return $response;
        }

        // Create log
        ActivityLog::createLog([
            'activity' => $activity,
            'module' => $module,
            'description' => $this->getDescription($request, $activity, $module),
            'new_data' => $this->getRequestData($request),
        ]);

        return $response;
    }

    /**
     * Get activity type from HTTP method.
     */
    private function getActivityType(string $method): string
    {
        return match($method) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'unknown',
        };
    }

    /**
     * Get module from request path.
     */
    private function getModule(string $path): ?string
    {
        // Remove api/ prefix
        $path = str_replace('api/', '', $path);

        // Extract module
        if (str_contains($path, 'savings')) return 'savings';
        if (str_contains($path, 'loans')) return 'loans';
        if (str_contains($path, 'installments')) return 'installments';
        if (str_contains($path, 'service-allowances')) return 'service_allowances';
        if (str_contains($path, 'gifts')) return 'gifts';
        if (str_contains($path, 'members') || str_contains($path, 'users')) return 'users';
        if (str_contains($path, 'cash-accounts')) return 'cash_accounts';
        if (str_contains($path, 'accounting-periods')) return 'accounting_periods';
        if (str_contains($path, 'chart-of-accounts')) return 'chart_of_accounts';

        return null;
    }

    /**
     * Get description.
     */
    private function getDescription(Request $request, string $activity, string $module): string
    {
        $user = auth()->user();
        $action = match($activity) {
            'create' => 'membuat',
            'update' => 'mengubah',
            'delete' => 'menghapus',
            default => 'melakukan operasi pada',
        };

        $moduleName = match($module) {
            'savings' => 'simpanan',
            'loans' => 'pinjaman',
            'installments' => 'cicilan',
            'service_allowances' => 'jasa pelayanan',
            'gifts' => 'hadiah',
            'users' => 'data pengguna',
            'cash_accounts' => 'kas',
            'accounting_periods' => 'periode akuntansi',
            'chart_of_accounts' => 'akun',
            default => $module,
        };

        return "{$user->full_name} {$action} {$moduleName}";
    }

    /**
     * Get request data (sanitized).
     */
    private function getRequestData(Request $request): ?array
    {
        $data = $request->except(['password', 'password_confirmation', 'token']);
        
        return !empty($data) ? $data : null;
    }
}