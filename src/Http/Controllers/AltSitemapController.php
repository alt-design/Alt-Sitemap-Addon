<?php

declare(strict_types=1);

namespace AltDesign\AltSitemap\Http\Controllers;

use AltDesign\AltSitemap\Helpers\Data;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Statamic\Entries\EntryCollection;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;
use Statamic\Fields\Fields;
use Statamic\Taxonomies\TermCollection;

class AltSitemapController
{
    private array $manualItems = [];

    public function index(): View
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

    public function update(Request $request): bool
    {
        $data = new Data('settings');

        $blueprint = $data->getBlueprint(true);

        $fields = $blueprint->fields()->addValues($request->all());

        $fields->validate();

        $data->setAll($fields->process()->values()->toArray());

        return true;
    }

    /**
     * Add an explicit item to the sitemap.
     *
     * @param  array<int,mixed>  $item
     */
    public function registerItem(array $item): void
    {
        $this->manualItems[] = $item;
    }

    /**
     * @param  array<int,mixed>  $items
     */
    public function registerItems(array $items): void
    {
        foreach ($items as $item) {
            $this->registerItem($item);
        }
    }

    public function generateSitemap(Request $request): HttpResponse
    {
        $data = new Data('settings');

        $blueprint = $data->getBlueprint(true);

        $fields = $blueprint->fields()->addValues($data->all())->preProcess();

        if (config('statamic.eloquent-driver.entries.driver') === 'eloquent') {
            return $this->generateEloquentSitemap($request, $fields);
        }

        return $this->generateFlatFileSitemap($request, $fields);
    }

    private function generateEloquentSitemap(Request $request, Fields $fields): HttpResponse
    {
        $settings = [];

        $excludeTaxonomiesFromSitemap = $fields->values()->toArray()['exclude_taxonomies_from_sitemap'];
        $excludeCollectionFromSitemap = $fields->values()->toArray()['exclude_collections_from_sitemap'];

        $defaultCollectionPriorities = $fields->values()->toArray()['default_collection_priorities'];
        $defaultTaxonomyPriorities = $fields->values()->toArray()['default_taxonomy_priorities'];

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

        $site_url = $request->getSchemeAndHttpHost();

        $entries = $this->getEloquentEntries();

        $entries = $entries->filter(function ($entry) use ($excludeCollectionFromSitemap) {
            return $entry['published'] === true &&
                $entry['uri'] !== null &&
                $entry['exclude_from_sitemap'] === false &&
                in_array($entry['collection'], $excludeCollectionFromSitemap) === false;
        });

        $items = $entries->map(function ($entry) use ($settings) {
            $priority = 0.5;

            foreach ($settings as $setting) {
                if ($entry['collection'] === $setting[0]) {
                    $priority = $setting[1];
                }
            }

            $priority = $entry['sitemap_priority'] ?? $priority;

            return [$entry['uri'], $entry['updated_at'], $priority];
        });

        if (config('statamic.eloquent-driver.terms.driver') === 'eloquent') {
            $terms = $this->getEloquentTerms();

            $terms = $terms->filter(function ($term) use ($excludeTaxonomiesFromSitemap) {
                return $term['exclude_from_sitemap'] === false && in_array($term['taxonomy'], $excludeTaxonomiesFromSitemap) === false;
            })->each(function ($term) use (&$items, $settings) {
                $priority = 0.5;

                foreach ($settings as $setting) {
                    if ($term['taxonomy'] === $setting[0]) {
                        $priority = $setting[1];
                    }
                }

                $priority = $term['sitemap_priority'] ?? $priority;

                $items[] = [$term['uri'], $term['updated_at'], $priority];
            });
        } else {
            $terms = $this->getFlatFileTerms();

            $terms = $terms->filter(function ($term) use ($excludeTaxonomiesFromSitemap) {
                return $term->exclude_from_sitemap === false && in_array($term->taxonomy->handle, $excludeTaxonomiesFromSitemap) === false;
            })->each(function ($term) use (&$items, $settings) {
                $priority = 0.5;

                foreach ($settings as $setting) {
                    if ($term->taxonomy->handle === $setting[0]) {
                        $priority = $setting[1];
                    }
                }

                $priority = $term->sitemap_priority ?? $priority;

                $items[] = [$term->url, $term->lastModified()->format('Y-m-d\TH:i:sP'), $priority];
            });

            foreach ($terms as $term) {
                $priority = 0.5;
                $termTaxonomy = $term->taxonomy->handle;

                foreach ($settings as $setting) {
                    if ($termTaxonomy === $setting[0]) {
                        $priority = $setting[1];
                    }
                }
                // override with priority from entry if set
                $priority = $term->sitemap_priority ?? $priority;
                $items[] = [$term->url, $term->lastModified()->format('Y-m-d\TH:i:sP'), $priority];
            }
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

    private function generateFlatFileSitemap(Request $request, Fields $fields): HttpResponse
    {
        $settings = [];
        $items = [];

        $excludeTaxonomiesFromSitemap = $fields->values()->toArray()['exclude_taxonomies_from_sitemap'];
        $excludeCollectionFromSitemap = $fields->values()->toArray()['exclude_collections_from_sitemap'];

        $site_url = url('');
        $entries = $this->getFlatFileEntries();

        $entries = $entries->filter(function ($entry) use ($excludeCollectionFromSitemap) {
            return $entry->published() === true &&
                $entry->url() !== null &&
                $entry->exclude_from_sitemap === false &&
                in_array($entry->collection->handle, $excludeCollectionFromSitemap) === false;
        });

        foreach ($entries as $entry) {
            // check if entry collection matches setting[0], if so apply setting[1] as priority
            $priority = 0.5;

            foreach ($settings as $setting) {
                if ($entry->collection->handle === $setting[0]) {
                    $priority = $setting[1];
                }
            }
            // override with priority from entry if set
            $priority = $entry->sitemap_priority ?? $priority;
            $items[] = [$entry->url, $entry->lastModified()->format('Y-m-d\TH:i:sP'), $priority];
        }

        $terms = Term::all();

        $terms = $terms->filter(function ($term) use ($excludeTaxonomiesFromSitemap) {
            return $term->exclude_from_sitemap === false && in_array($term->taxonomy->handle, $excludeTaxonomiesFromSitemap) === false;
        });

        foreach ($terms as $term) {
            // check if term taxonomy matches setting[0], if so apply setting[1] as priority
            $priority = 0.5;
            $termTaxonomy = $term->taxonomy->handle;

            foreach ($settings as $setting) {
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

    private function getEloquentTerms(): Collection
    {
        return DB::table('taxonomy_terms')
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
    }

    private function getFlatFileTerms(): TermCollection
    {
        return Term::all();
    }

    private function getEloquentEntries(): Collection
    {
        return DB::table('entries')->select(
            'id',
            'uri',
            'data->title as title',
            'data->sitemap_priority as sitemap_priority',
            'data->exclude_from_sitemap as exclude_from_sitemap',
            'published',
            'collection',
            'updated_at'
        )->get()->map(function ($entry) {
            return [
                'id' => $entry->id,
                'uri' => $entry->uri,
                'title' => $entry->title,
                'sitemap_priority' => (int) $entry->sitemap_priority,
                'exclude_from_sitemap' => $entry->exclude_from_sitemap === 'true',
                'published' => (bool) $entry->published,
                'collection' => $entry->collection,
                'updated_at' => Carbon::make($entry->updated_at)->format('Y-m-d\TH:i:sP'),
            ];
        });
    }

    private function getFlatFileEntries(): EntryCollection
    {
        return Entry::all();
    }
}
