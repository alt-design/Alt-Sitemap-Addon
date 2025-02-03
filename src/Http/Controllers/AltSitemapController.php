<?php

declare(strict_types=1);

namespace AltDesign\AltSitemap\Http\Controllers;

use AltDesign\AltSitemap\Helpers\Data;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;

class AltSitemapController
{
    private array $manualItems = [];

    public function index()
    {
        $data = new Data('settings');

        $blueprint = $data->getBlueprint(true);

        $fields = $blueprint->fields()->addValues($data->all())->preProcess();

        return view('alt-sitemap::index', [
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
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
     *
     * @return void
     */
    public function registerItem(array $item)
    {
        $this->manualItems[] = $item;
    }

    /**
     * @return void
     */
    public function registerItems(array $items)
    {
        foreach ($items as $item) {
            $this->registerItem($item);
        }
    }

    public function generateSitemap(Request $request): HttpResponse
    {
        if (config('statamic.eloquent-driver.entries.driver') === 'eloquent') {
            return $this->generateEloquentSitemap($request);
        }

        return $this->generateFlatFileSitemap();

    }

    private function generateEloquentSitemap(Request $request): HttpResponse
    {
        $data = new Data('settings');
        $blueprint = $data->getBlueprint(true);
        $fields = $blueprint->fields()->addValues($data->all())->preProcess();

        $defaultCollectionPriorities = $fields->values()->toArray()['default_collection_priorities'];
        $excludeCollectionFromSitemap = $fields->values()->toArray()['exclude_collections_from_sitemap'];
        $defaultTaxonomyPriorities = $fields->values()->toArray()['default_taxonomy_priorities'];
        $excludeTaxonomiesFromSitemap = $fields->values()->toArray()['exclude_taxonomies_from_sitemap'];

        foreach ($defaultCollectionPriorities as $value) {
            $collection = $value['collection'][0];
            $priority = $value['priority'];
            $defaultCollectionSettings[] = [$collection, $priority];
        }

        foreach ($defaultTaxonomyPriorities as $value) {
            $taxonomy = $value['taxonomy'][0];
            $priority = $value['priority'];
            $defaultTaxonomySettings[] = [$taxonomy, $priority];
        }

        $site_url = $request->getSchemeAndHttpHost();

        $items = [];

        $entries = DB::table('entries')
            ->select(
                'id',
                'uri',
                'data->title as title',
                'data->sitemap_priority as sitemap_priority',
                'data->exclude_from_sitemap as exclude_from_sitemap',
                'collection',
                'updated_at',
                'published'
            )
            ->get()
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'uri' => $entry->uri,
                    'sitemap_priority' => (int) $entry->sitemap_priority,
                    'exclude_from_sitemap' => $entry->exclude_from_sitemap === 'true',
                    'collection' => $entry->collection,
                    'published' => $entry->published,
                    'updated_at' => Carbon::make($entry->updated_at)->format('Y-m-d\TH:i:sP'),
                ];
            });

        foreach ($entries as $entry) {
            $url = $entry['uri'];

            // Skip if the entry is not published
            if ($entry['published'] === false) {
                continue;
            }

            // skip if to be excluded
            if ($entry['exclude_from_sitemap'] === true) {
                continue;
            }

            // skip if collection is to be excluded
            if (in_array($entry['collection'], $excludeCollectionFromSitemap)) {
                continue;
            }

            // if the collection has no route setup, skip
            if ($url === null) {
                continue;
            }

            // check if entry collection matches setting[0], if so apply setting[1] as priority
            $priority = 0.5;
            // $entryCollection = $entry->collection->handle;
            $entryCollection = $entry['collection'];

            foreach ($defaultCollectionSettings ?? [] as $setting) {
                if ($entryCollection === $setting[0]) {
                    $priority = $setting[1];
                }
            }

            // override with priority from entry if set
            $priority = $entry['sitemap_priority'] ?? $priority;
            $items[] = [$url, $entry['updated_at'], $priority];
        }

        // Add terms to sitemap
        $terms = DB::table('taxonomy_terms')
            ->select(
                'id',
                'site',
                'slug',
                'uri',
                'taxonomy',
                'created_at',
                'updated_at',
            )
            ->get()
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'site' => $entry->site,
                    'slug' => $entry->slug,
                    'uri' => $entry->uri,
                    'taxonomy' => $entry->taxonomy,
                    'exclude_from_sitemap' => $entry->exclude_from_sitemap ?? false,
                    'created_at' => Carbon::make($entry->created_at)->format('Y-m-d\TH:i:sP'),
                    'updated_at' => Carbon::make($entry->updated_at)->format('Y-m-d\TH:i:sP'),
                ];
            });

        foreach ($terms as $term) {
            $url = $term['uri'];

            // skip if term is to be excluded
            if ($term['exclude_from_sitemap']) {
                continue;
            }

            // skip if taxonomy is to be excluded
            if (in_array($term['taxonomy'], $excludeTaxonomiesFromSitemap)) {
                continue;
            }

            // check if term taxonomy matches setting[0], if so apply setting[1] as priority
            $priority = 0.5;
            $termTaxonomy = $term['taxonomy'];

            foreach ($defaultTaxonomySettings ?? [] as $setting) {
                if ($termTaxonomy === $setting[0]) {
                    $priority = $setting[1];
                }
            }
            // override with priority from entry if set
            $priority = $term->sitemap_priority ?? $priority;
            $items[] = [$url, $term['updated_at'], $priority];
        }

        foreach ($this->manualItems as $manualItem) {
            $url = $manualItem[0] ?? null;

            if (empty($url)) {
                continue;
            }
            $lastModified = $manualItem[1]->format('c') ?? \Carbon\Carbon::now()->format('c');
            $priority = $manualItem[2] ?? 0.5;
            $items[] = [$url, $lastModified, $priority];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($items as $item) {
            $xml .= '<url><loc>' . $site_url . $item[0] . '</loc><lastmod>' . $item[1] . '</lastmod><priority>' . $item[2] . '</priority></url>';
        }
        $xml .= '</urlset>';

        return Response::make($xml, 200, ['Content-Type' => 'application/xml']);
    }

    private function generateFlatFileSitemap(): HttpResponse
    {
        // get blueprint setting values
        $data = new Data('settings');
        $blueprint = $data->getBlueprint(true);
        $fields = $blueprint->fields()->addValues($data->all())->preProcess();
        $defaultCollectionPriorities = $fields->values()->toArray()['default_collection_priorities'];
        $excludeCollectionFromSitemap = $fields->values()->toArray()['exclude_collections_from_sitemap'];
        $defaultTaxonomyPriorities = $fields->values()->toArray()['default_taxonomy_priorities'];
        $excludeTaxonomiesFromSitemap = $fields->values()->toArray()['exclude_taxonomies_from_sitemap'];

        foreach ($defaultCollectionPriorities as $value) {
            $collection = $value['collection'][0];
            $priority = $value['priority'];
            $settings[] = [$collection, $priority];
        }

        foreach ($defaultTaxonomyPriorities as $value) {
            $taxonomy = $value['taxonomy'][0];
            $priority = $value['priority'];
            $settings[] = [$taxonomy, $priority];
        }

        $site_url = url('');
        $entries = Entry::all();

        foreach ($entries as $entry) {
            // Skip if the entry is not published
            if (! $entry->published()) {
                continue;
            }

            // skip if to be excluded
            if ($entry->exclude_from_sitemap) {
                continue;
            }

            // skip if collection is to be excluded
            if (in_array($entry->collection->handle, $excludeCollectionFromSitemap)) {
                continue;
            }

            // if the collection has no route setup, skip
            if ($entry->url() === null) {
                continue;
            }

            // check if entry collection matches setting[0], if so apply setting[1] as priority
            $priority = 0.5;
            $entryCollection = $entry->collection->handle;

            foreach ($settings ?? [] as $setting) {
                if ($entryCollection === $setting[0]) {
                    $priority = $setting[1];
                }
            }
            // override with priority from entry if set
            $priority = $entry->sitemap_priority ?? $priority;
            $items[] = [$entry->url, $entry->lastModified()->format('Y-m-d\TH:i:sP'), $priority];
        }

        // Add terms to sitemap
        $terms = Term::all();

        foreach ($terms as $term) {

            // skip if term is to be excluded
            if ($term->exclude_from_sitemap) {
                continue;
            }

            // skip if taxonomy is to be excluded
            if (in_array($term->taxonomy->handle, $excludeTaxonomiesFromSitemap)) {
                continue;
            }

            // check if term taxonomy matches setting[0], if so apply setting[1] as priority
            $priority = 0.5;
            $termTaxonomy = $term->taxonomy->handle;

            foreach ($settings ?? [] as $setting) {
                if ($termTaxonomy === $setting[0]) {
                    $priority = $setting[1];
                }
            }
            // override with priority from entry if set
            $priority = $term->sitemap_priority ?? $priority;
            $items[] = [$term->url, $term->lastModified()->format('Y-m-d\TH:i:sP'), $priority];
        }

        foreach ($this->manualItems as $manualItem) {
            $url = $manualItem[0] ?? null;

            if (empty($url)) {
                continue;
            }
            $lastModified = $manualItem[1]->format('c') ?? \Carbon\Carbon::now()->format('c');
            $priority = $manualItem[2] ?? 0.5;
            $items[] = [$url, $lastModified, $priority];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($items as $item) {
            $xml .= '<url><loc>' . $site_url . $item[0] . '</loc><lastmod>' . $item[1] . '</lastmod><priority>' . $item[2] . '</priority></url>';
        }
        $xml .= '</urlset>';

        return Response::make($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
