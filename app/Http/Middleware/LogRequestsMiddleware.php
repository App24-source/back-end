<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogRequestsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Log the incoming request details
        // \Log::info('Incoming Request', [
        //     'url' => $request->fullUrl(),          // Full URL of the request
        //     'method' => $request->method(),        // HTTP method (GET, POST, etc.)
        //     'ip' => $request->ip(),                // IP address of the requester
        //     'user' => $request->user() ? $request->user()->id : 'Guest', // User ID or 'Guest'
        // ]);
        return $next($request);
    }
}
