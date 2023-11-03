<?php namespace AltDesign\AltSitemap\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class AltSitemapController
{
    public function index(Request $request)
    {
        $site_url = $request->getSchemeAndHttpHost();
        $sitemap = $this->generateSitemap($site_url);
        return Response::make($sitemap, 200, ['Content-Type' => 'application/xml']);
    }

    private function generateSitemap($site_url)
    {
        $entries = Entry::all();
        foreach ($entries as $entry) {
            $items[] = array($entry->url, $entry->lastModified()->format('Y-m-d\TH:i:sP'));
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($items as $item) {
            $xml .='<url><loc>'.$site_url.$item[0].'</loc><lastmod>'.$item[1].'</lastmod></url>';
        }
        $xml .= '</urlset>';
        return $xml;
    }
}
