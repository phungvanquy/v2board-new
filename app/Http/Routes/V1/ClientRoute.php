<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ClientRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'client',
            'middleware' => 'client',
        ], function ($router) {
            // Keep the historical API endpoint available as a compatibility
            // alias when a custom subscription path is configured. Both
            // routes use the same token middleware and controller.
            $router->get('/subscribe', 'V1\\Client\\ClientController@subscribe');
            // App
            $router->get('/app/getConfig', 'V1\\Client\\AppController@getConfig');
            $router->get('/app/getVersion', 'V1\\Client\\AppController@getVersion');
        });
    }
}
