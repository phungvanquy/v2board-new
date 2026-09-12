<?php

namespace App\Http\Middleware;

use Closure;

class CORS
{
    public function handle($request, Closure $next)
    {
        $origin = $request->header('origin');
        if (empty($origin)) {
            $referer = $request->header('referer');
            if (!empty($referer) && preg_match("/^((https|http):\/\/)?([^\/]+)/i", $referer, $matches)) {
                $origin = $matches[0];
            }
        }
        $response = $next($request);
        $headers = $response->headers;
        $headers->set('Access-Control-Allow-Origin', trim((string) $origin, '/'));
        $headers->set('Access-Control-Allow-Methods', 'GET,POST,OPTIONS,HEAD');
        $headers->set('Access-Control-Allow-Headers', 'Origin,Content-Type,Accept,Authorization,X-Request-With');
        $headers->set('Access-Control-Allow-Credentials', 'true');
        $headers->set('Access-Control-Max-Age', '10080');

        return $response;
    }
}
