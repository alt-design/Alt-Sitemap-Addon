<?php

namespace AltDesign\AltSitemap;

use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];
    public function bootAddon()
    {
        //
    }
}
