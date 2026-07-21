<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthenticateWebhook
{
    public function handle(Request $request, Closure $next)
    {
        $validToken = env('BOUNCE_WEBHOOK_TOKEN');
        
        // Skip validation if no token is set (for development)
        if (empty($validToken)) {
            Log::channel('bounces')->warning('Webhook token validation skipped - no token set');
            return $next($request);
        }

        // Check token from query parameter or header
        $providedToken = $request->input('token') ?? $request->header('X-Webhook-Token');

        if ($providedToken !== $validToken) {
            Log::channel('bounces')->warning('Invalid webhook token attempt', [
                'provided_token' => $providedToken ? '***' . substr($providedToken, -4) : 'none',
                'expected_token' => '***' . substr($validToken, -4),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl()
            ]);

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid webhook token'
            ], 401);
        }

        Log::channel('bounces')->debug('Webhook token validated successfully');
        return $next($request);
    }
}