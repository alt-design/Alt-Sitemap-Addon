# Alt Sitemap

> Alt Sitemap is a Statamic addon for creating sitemaps to help search engines discover URLs on your site

## Features

- Create basic sitemaps detailing <loc> <lastmod> and <priority> of all entries in your Statamic site.
- Set priority of entire collections/taxonomies
- Set priority of specific entries/terms
- Exclude certain entries/terms from the sitemap.
- Exclude entire collections/taxonomies from the sitemap.

## How to Install

You can search for this addon in the `Tools > Addons` section of the Statamic control panel and click **install**, or run the following command from your project root:

``` bash
composer require alt-design/alt-sitemap
```

## How to Use

After installation, access your sitemap at /sitemap.xml  

- Set priorities for entire collections/taxonomies in CP > Tools > Alt Sitemap.
- Exclude entire collections/taxonomies in CP > Tools > Alt Sitemap.
- Set entry/term priorities in the entry/term under the Alt Sitemap tab. Entry priorities will override collection/taxonomy priorities.  
- Priorities are set as 0.5 by default.  
- Exclude entries/terms from the sitemap in the entry under the Alt Sitemap tab.
- <lastmod> is set to the last updated date of the entry/term.

## Manual Entries
Add a single, or multiple items to the sitemap, using code similar to the following in a service provider's boot() (e.g. AppServiceProvider) method.

Using registerItem() to register a single item.
```
$this->callAfterResolving(
    AltSitemapController::class,
    function ($altSitemapController) {
        $altSitemapController->registerItem(
            [
                '/url-1',
            ]
        );
    }
);
```

Using registerItems() to register multiple items at once.
```
$this->callAfterResolving(
     AltSitemapController::class,
     function ($altSitemapController) {
         $altSitemapController->registerItems(
             [
                 [
                     '/url-1',
                 ],
                 [
                     '/url-2',
                     Carbon::create(2024,10,23,12,20,0, 'UTC'),
                 ],
                 [
                     '/url-3',
                     Carbon::create(2024,10,23,12,20,0, 'UTC'),
                     0.8
                 ],
             ]
         );
     }
 );
```

## Questions etc

Drop us a big shout-out if you have any questions, comments, or concerns. We're always looking to improve our addons, so if you have any feature requests, we'd love to hear them.

### Starter Kits
- [Alt Starter Kit](https://statamic.com/starter-kits/alt-design/alt-starter-kit) 

### Addons
- [Alt Redirect Addon](https://github.com/alt-design/Alt-Redirect-Addon)
- [Alt Sitemap Addon](https://github.com/alt-design/Alt-Sitemap-Addon)
- [Alt Akismet Addon](https://github.com/alt-design/Alt-Akismet-Addon)
- [Alt Password Protect Addon](https://github.com/alt-design/Alt-Password-Protect-Addon)
- [Alt Cookies Addon](https://github.com/alt-design/Alt-Cookies-Addon)
- [Alt Inbound Addon](https://github.com/alt-design/Alt-Inbound-Addon)
- [Alt Google 2FA Addon](https://github.com/alt-design/Alt-Google-2fa-Addon)

## Postcardware

Send us a postcard from your hometown if you like this addon. We love getting mail from other cool peeps!

Alt Design  
St Helens House  
Derby  
DE1 3EE  
UK  
