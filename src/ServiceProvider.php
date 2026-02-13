<?php

namespace AltDesign\AltSitemap;

use Facades\Statamic\Version;

use Illuminate\Support\Str;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;

use Illuminate\Support\Facades\Event;
use AltDesign\AltSitemap\Events\Sitemap;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'web' => __DIR__ . '/../routes/web.php',
        'cp' => __DIR__ . '/../routes/cp.php',
    ];

    public function addToNav()
    {
        Nav::extend(function ($nav)
        {
            $nav->content('Alt Sitemap')
                ->section('Tools')
                ->can('view alt-sitemap')
                ->route('alt-sitemap.index')
                ->icon(config('alt-sitemap.alt_sitemap_icon'));
        });
    }

    public function registerPermissions()
    {
        Permission::register('view alt-sitemap')
            ->label('View Alt Sitemap Settings');
    }

    public function registerEvents()
    {
        Event::subscribe(Sitemap::class);
    }

    public function bootAddon()
    {
        $this->addToNav();
        $this->registerPermissions();
        $this->registerEvents();

        // Statamic >= V6 - unbind the settings blueprint to remove the default settings page and permissions 
        // as we are handling this manually instead
        if(intval(Str::before(Version::get(), '.')) >= 6) {
            app()->offsetUnset("statamic.addons.alt-sitemap.settings_blueprint");
        }
    }
}
