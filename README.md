# Alt Sitemap

> Alt Sitemap is a Statamic addon for creating sitemaps to help search engines discover URLs on your site

## Features

- Create basic sitemaps detailing <loc> <lastmod> and <priority> of all entries in your Statamic site.
- Set priority of collections
- Set priority of entries
- Exclude certain entries from the sitemap.

## How to Install

You can search for this addon in the `Tools > Addons` section of the Statamic control panel and click **install**, or run the following command from your project root:

``` bash
composer require alt-design/alt-sitemap
```

## How to Use

After installation, access your sitemap at /sitemap.xml
Set collection priorities in CP > Tools > Alt Sitemap. Select collection and priority value.
Set entry priorities in the entry under the Alt Sitemap tab. Entry priorities will override Collection priorities.
Priorities are set as 0.5 by default.
Exclude entries from the sitemap in the entry under the Alt Sitemap tab.

