<?php namespace AltDesign\AltSitemap\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Statamic\Facades\Entry;
use AltDesign\AltSitemap\Helpers\Data;
use Carbon\Carbon;
use Statamic\Facades\Term;
use function in_array;
use function url;

class AltSitemapController
{
    /**
     * @var array
     */
    private $manualItems = [];

    /**
     * @var string
     */
    private $site_url = '';

    public function __construct() {
        $this->site_url = url('');
    }

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

    /**
     * Add an explicit item to the sitemap.
     *
     * @param  array  $item
     *
     * @return void
     */
    public function registerItem(array $item) {
        $this->manualItems[] = $item;
    }

    /**
     * @param  array  $items
     *
     * @return void
     */
    public function registerItems(array $items) {
        foreach ($items as $item) {
            $this->registerItem($item);
        }
    }

    public function generateSitemap(Request $request)
    {
        //get blueprint setting values
        $data = new Data('settings');
        $blueprint = $data->getBlueprint(true);
        $fields = $blueprint->fields()->addValues($data->all())->preProcess();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $xml .= $this->generateEntries($fields);
        $xml .= $this->generateTaxonomies($fields);
        $xml .= $this->generateManualItems();
        $xml .= '</urlset>';

        return Response::make($xml, 200, ['Content-Type' => 'application/xml']);
    }

    private function generateEntries($fields) {
        $defaultCollectionPriorities = $fields->values()->toArray()['default_collection_priorities'];
        $excludeCollectionFromSitemap = $fields->values()->toArray()['exclude_collections_from_sitemap'];

        foreach ($defaultCollectionPriorities as $value) {
            $settings[ $value['collection'][0] ] = $value['priority'];
        }

        $entries = Entry::all();
        $items = [];
        foreach ($entries as $entry) {
            // Skip if the entry is not published
            if (!$entry->published()) {
                continue;
            }

            // Skip if to be excluded
            if ($entry->exclude_from_sitemap == true) {
                continue;
            }

            // Skip if collection is to be excluded
            if (in_array($entry->collection->handle, $excludeCollectionFromSitemap)) {
                continue;
            }

            // If the collection has no route setup, skip
            if ($entry->url() === null) {
                continue;
            }

            // Check if entry collection matches setting[0], if so apply setting[1] as priority
            $priority = $settings[ $entry->collection->handle ] ?? 0.5;

            // Override with priority from entry if set
            $priority = $entry->sitemap_priority ?? $priority;
            $items[] = array($entry->url, $entry->lastModified()->format('Y-m-d\TH:i:sP'), $priority);
        }

        return $this->generateXmlFragments($items);
    }

    /**
     * @param $fields
     *
     * @return string
     */
    private function generateTaxonomies($fields): string {
        $defaultTaxonomyPriorities = $fields->values()->toArray()['default_taxonomy_priorities'];
        $excludeTaxonomiesFromSitemap = $fields->values()->toArray()['exclude_taxonomies_from_sitemap'];

        foreach ($defaultTaxonomyPriorities as $value) {
            $taxonomy = $value['taxonomy'][0];
            $priority = $value['priority'];
            $settings[$taxonomy] = $priority ;
        }

        $terms = Term::all();
        $items = [];
        foreach ($terms as $term) {
            // Skip if to be excluded
            //            if ($term->exclude_from_sitemap == true) { // FIXME
            //                continue;
            //            }

            // Skip if taxonomy is to be excluded
            if (in_array($term->taxonomy->handle, $excludeTaxonomiesFromSitemap)) {
                continue;
            }

            // If the taxonomy has route, skip
            if ($term->url() === null) {
                continue;
            }

            // Calculate priority.
            $priority = $settings[$term->taxonomy->handle] ?? 0.5;

            // Override with priority from entry if set
            //            $priority = $term->sitemap_priority ?? $priority; // FIXME
            $items[] = array($term->url, $term->lastModified()->format('Y-m-d\TH:i:sP'), $priority);
        }

        return $this->generateXmlFragments($items);
    }

    /**
     * @return string
     */
    private function generateManualItems(): string {
        $items = [];
        foreach ($this->manualItems as $manualItem) {
            $url = $manualItem[0] ?? null;
            if (empty($url)) {
                continue;
            }
            $lastModified = $manualItem[1]->format('c') ?? \Carbon\Carbon::now()->format('c');
            $priority = $manualItem[2] ?? 0.5;
            $items[] = [$url, $lastModified, $priority];
        }
        
        return $this->generateXmlFragments($items);
    }

    /**
     * @param array $items
     *
     * @return string
     */
    private function generateXmlFragments($items): string {
        $fragment = '';
        foreach ($items as $item) {
            $fragment .='<url><loc>'.$this->site_url.$item[0].'</loc><lastmod>'.$item[1].'</lastmod><priority>'.$item[2].'</priority></url>';
        }

        return $fragment;
    }
}
