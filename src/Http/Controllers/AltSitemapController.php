<?php namespace AltDesign\AltSitemap\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Statamic\Facades\Entry;
use AltDesign\AltSitemap\Helpers\Data;

class AltSitemapController
{
    public function index()
    {
        $data = new Data('settings');

        $blueprint = $data->getBlueprint(true);
        $fields = $blueprint->fields()->addValues($data->all())->preProcess();

        return view('alt-sitemap::index', [
            'blueprint' => $blueprint->toPublishArray(),
            'values'    => $fields->values(),
            'meta'      => $fields->meta(),
        ]);
    }

    public function update(Request $request)
    {
        $data = new Data('settings');
        // Set the fields etc
        $blueprint = $data->getBlueprint(true);
        $fields = $blueprint->fields()->addValues($request->all());
        $fields->validate();

        // Save the data
        $data->setAll($fields->process()->values()->toArray());

        return true;
    }

    public function generateSitemap(Request $request)
    {
        //get blueprint setting values
        $data = new Data('settings');
        $blueprint = $data->getBlueprint(true);
        $fields = $blueprint->fields()->addValues($data->all())->preProcess();
        $defaultCollectionPriotities = $fields->values()->toArray();
        foreach ($defaultCollectionPriotities as $value) {
            foreach ($value as $v){
                $collection = $v['collection'][0];
                $priority = $v['priority'];
                $settings[] = array($collection, $priority) ;
            }
        }

        $site_url = $request->getSchemeAndHttpHost();
        $entries = Entry::all();
        foreach ($entries as $entry) {
            // skip if to be excluded
            if ($entry->exclude_from_sitemap == true) {
                continue;
            }

            //check if entry collection matches setting[0], if so apply setting[1] as priority
            $priority = 0.5;
            $entryCollection = $entry->collection->handle;
            foreach ($settings ?? [] as $setting) {
                if ($entryCollection == $setting[0]) {
                    $priority = $setting[1];
                }
            }
            // override with priority from entry if set
            $priority = $entry->sitemap_priority ?? $priority;
            $items[] = array($entry->url, $entry->lastModified()->format('Y-m-d\TH:i:sP'), $priority);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($items as $item) {
            $xml .='<url><loc>'.$site_url.$item[0].'</loc><lastmod>'.$item[1].'</lastmod><priority>'.$item[2].'</priority></url>';
        }
        $xml .= '</urlset>';
        return Response::make($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
