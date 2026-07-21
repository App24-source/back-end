<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
//     /**
//      * Handle an incoming request.
//      *
//      * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
//      */
//     public function handle(Request $request, Closure $next): Response
//     {
//         $response = $next($request);
//         return $response->header('Access-Control-Allow-Origin', 'http://localhost:3000')
//                     ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE')
//                     ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
//     }
// }



public function handle(Request $request, Closure $next): Response
{
    $response = $next($request);

    $headers = [
        'Access-Control-Allow-Origin'   => 'http://localhost:3000',
        'Access-Control-Allow-Methods'  => 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers'  => 'Content-Type, Authorization',
        // Needed so the browser can read Content-Disposition from downloads
        'Access-Control-Expose-Headers' => 'Content-Length, Content-Type, Content-Disposition',
    ];

    if ($request->getMethod() === 'OPTIONS') {
        return response('', 204)->withHeaders($headers);
    }

    if (method_exists($response, 'header')) {
        foreach ($headers as $k => $v) {
            $response->header($k, $v);
        }
        return $response;
    }

    foreach ($headers as $k => $v) {
        $response->headers->set($k, $v);
    }
    return $response;
}
}