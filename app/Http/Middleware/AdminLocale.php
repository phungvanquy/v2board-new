<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;

class AdminLocale
{
    /**
     * Keep every admin surface in English, independently of the public-site locale.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $previousLocale = App::getLocale();
        App::setLocale('en-US');

        try {
            return $next($request);
        } finally {
            App::setLocale($previousLocale);
        }
    }
}
