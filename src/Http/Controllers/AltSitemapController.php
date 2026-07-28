<?php

declare(strict_types=1);

namespace AltDesign\AltSitemap\Http\Controllers;

use Carbon\Carbon;
use Statamic\Facades\Term;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Fields\Fields;
use Illuminate\Http\Request;
use Statamic\CP\PublishForm;
use Facades\Statamic\Version;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\View\View;
use Statamic\Entries\EntryCollection;
use AltDesign\AltSitemap\Helpers\Data;
use Statamic\Taxonomies\TermCollection;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Response as HttpResponse;

class AltSitemapController
{
    private array $manualItems = [];

    public function index(): View|PublishForm
    {
        $data = new Data('settings');

        $blueprint = $data->getBlueprint(true);

        $fields = $blueprint->fields()->addValues($data->all())->preProcess();

        // Statamic >= V6
        if(intval(Str::before(Version::get(), '.')) >= 6) {
            $blueprint->setNamespace('alt-sitemap');

            return PublishForm::make($blueprint)
                ->title('Alt Sitemap')
                ->icon(config('alt-sitemap.alt_sitemap_icon'))
                ->values($fields->values()->toArray())
                ->submittingTo(cp_route('alt-sitemap.update'), 'POST');
        }

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
        [$items, $siteUrl] = $this->collectItems();

        if ($this->splitEnabled()) {
            return $this->renderIndex($this->applySplitGroups($items), $siteUrl);
        }

        return $this->renderUrlset($items, $siteUrl);
    }

    /**
     * Serve a single child sitemap (e.g. /sitemap-products.xml) containing only
     * the items for that collection/taxonomy handle.
     */
    public function generateChildSitemap(Request $request, string $group): HttpResponse
    {
        [$items, $siteUrl] = $this->collectItems();

        $items = $this->applySplitGroups($items);
        $items = array_values(array_filter($items, fn ($item) => $item[3] === $group));

        abort_if(empty($items), 404);

        return $this->renderUrlset($items, $siteUrl);
    }

    private function splitEnabled(): bool
    {
        return (bool) ((new Data('settings'))->get('split_sitemap') ?? false);
    }

    /**
     * Collections/taxonomies (by handle) the user chose to give their own child sitemap.
     *
     * @return array<int, string>
     */
    private function splitHandles(): array
    {
        $data = new Data('settings');

        return array_merge(
            $data->get('split_collections') ?? [],
            $data->get('split_taxonomies') ?? [],
        );
    }

    /**
     * Re-bucket items into child sitemaps: chosen handles keep their own file,
     * everything else shares /sitemap-other.xml. Empty selection = split all by handle.
     *
     * @param  array<int, array{0: string, 1: string, 2: mixed, 3: string}>  $items
     * @return array<int, array{0: string, 1: string, 2: mixed, 3: string}>
     */
    private function applySplitGroups(array $items): array
    {
        $splitHandles = $this->splitHandles();

        if (empty($splitHandles)) {
            return $items;
        }

        return array_map(function ($item) use ($splitHandles) {
            if (! in_array($item[3], $splitHandles, true)) {
                $item[3] = 'other';
            }

            return $item;
        }, $items);
    }

    /**
     * Build every sitemap item as [url, lastmod, priority, group].
     * Group is the collection/taxonomy handle, used to bucket child sitemaps.
     *
     * @return array{0: array<int, array{0: string, 1: string, 2: mixed, 3: string}>, 1: string}
     */
    private function collectItems(): array
    {
        $data = new Data('settings');
        $blueprint = $data->getBlueprint(true);
        $fields = $blueprint->fields()->addValues($data->all())->preProcess();

        $siteUrl = rtrim(url(''), '/');

        $items = config('statamic.eloquent-driver.entries.driver') === 'eloquent'
            ? $this->collectEloquentItems($fields)
            : $this->collectFlatFileItems($fields);

        foreach ($this->manualItems as $manualItem) {
            $url = $manualItem[0] ?? null;

            if (empty($url)) {
                continue;
            }

            $lastModified = isset($manualItem[1]) ? $manualItem[1]->format('c') : Carbon::now()->format('c');
            $priority = $manualItem[2] ?? 0.5;
            $group = $manualItem[3] ?? 'other';
            $items[] = [$url, $lastModified, $priority, $group];
        }

        return [$items, $siteUrl];
    }

    /**
     * Priorities are keyed by collection/taxonomy handle.
     *
     * @param  array<int, array{0: string, 1: mixed}>  $settings
     */
    private function priorityFor(string $handle, array $settings): mixed
    {
        $priority = 0.5;

        foreach ($settings as $setting) {
            if ($handle === $setting[0]) {
                $priority = $setting[1];
            }
        }

        return $priority;
    }

    /**
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function prioritySettings(array $values): array
    {
        $settings = [];

        foreach ($values['default_collection_priorities'] as $value) {
            $settings[] = [$value['collection'][0], $value['priority']];
        }

        foreach ($values['default_taxonomy_priorities'] as $value) {
            $settings[] = [$value['taxonomy'][0], $value['priority']];
        }

        return $settings;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: mixed, 3: string}>
     */
    private function collectEloquentItems(Fields $fields): array
    {
        $items = [];
        $values = $fields->values()->toArray();
        $settings = $this->prioritySettings($values);

        $excludeTaxonomiesFromSitemap = $values['exclude_taxonomies_from_sitemap'];
        $excludeCollectionFromSitemap = $values['exclude_collections_from_sitemap'];

        $entries = $this->getEloquentEntries()->filter(function ($entry) use ($excludeCollectionFromSitemap) {
            return $entry['published'] === true &&
                $entry['uri'] !== null &&
                $entry['exclude_from_sitemap'] === false &&
                in_array($entry['collection'], $excludeCollectionFromSitemap) === false;
        });

        foreach ($entries as $entry) {
            $priority = $entry['sitemap_priority'] ?? $this->priorityFor($entry['collection'], $settings);
            $items[] = [$entry['uri'], $entry['updated_at'], $priority, $entry['collection']];
        }

        if (config('statamic.eloquent-driver.terms.driver') === 'eloquent') {
            $terms = $this->getEloquentTerms()->filter(function ($term) use ($excludeTaxonomiesFromSitemap) {
                return $term['exclude_from_sitemap'] === false && in_array($term['taxonomy'], $excludeTaxonomiesFromSitemap) === false;
            });

            foreach ($terms as $term) {
                $priority = $term['sitemap_priority'] ?? $this->priorityFor($term['taxonomy'], $settings);
                $items[] = [$term['uri'], $term['updated_at'], $priority, $term['taxonomy']];
            }
        } else {
            $terms = $this->getFlatFileTerms()->filter(function ($term) use ($excludeTaxonomiesFromSitemap) {
                return $term->exclude_from_sitemap === false && in_array($term->taxonomy->handle, $excludeTaxonomiesFromSitemap) === false;
            });

            foreach ($terms as $term) {
                $priority = $term->sitemap_priority ?? $this->priorityFor($term->taxonomy->handle, $settings);
                $items[] = [$term->url, $term->lastModified()->format('Y-m-d\TH:i:sP'), $priority, $term->taxonomy->handle];
            }
        }

        return $items;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: mixed, 3: string}>
     */
    private function collectFlatFileItems(Fields $fields): array
    {
        $items = [];
        $values = $fields->values()->toArray();
        $settings = $this->prioritySettings($values);

        $excludeTaxonomiesFromSitemap = $values['exclude_taxonomies_from_sitemap'];
        $excludeCollectionFromSitemap = $values['exclude_collections_from_sitemap'];

        $entries = $this->getFlatFileEntries()->filter(function ($entry) use ($excludeCollectionFromSitemap) {
            return $entry->published() === true &&
                $entry->url() !== null &&
                $entry->exclude_from_sitemap === false &&
                in_array($entry->collection->handle, $excludeCollectionFromSitemap) === false;
        });

        foreach ($entries as $entry) {
            $priority = $entry->sitemap_priority ?? $this->priorityFor($entry->collection->handle, $settings);
            $items[] = [$entry->url, $entry->lastModified()->format('Y-m-d\TH:i:sP'), $priority, $entry->collection->handle];
        }

        $terms = Term::all()->filter(function ($term) use ($excludeTaxonomiesFromSitemap) {
            return $term->exclude_from_sitemap === false && in_array($term->taxonomy->handle, $excludeTaxonomiesFromSitemap) === false;
        });

        foreach ($terms as $term) {
            $priority = $term->sitemap_priority ?? $this->priorityFor($term->taxonomy->handle, $settings);
            $items[] = [$term->url, $term->lastModified()->format('Y-m-d\TH:i:sP'), $priority, $term->taxonomy->handle];
        }

        return $items;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: mixed, 3: string}>  $items
     */
    private function renderUrlset(array $items, string $siteUrl): HttpResponse
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($items as $item) {
            $loc = htmlspecialchars($siteUrl . $item[0], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $xml .= '<url><loc>' . $loc . '</loc><lastmod>' . $item[1] . '</lastmod><priority>' . $item[2] . '</priority></url>';
        }

        $xml .= '</urlset>';

        return Response::make($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: mixed, 3: string}>  $items
     */
    private function renderIndex(array $items, string $siteUrl): HttpResponse
    {
        // ponytail: string-max lastmod per group; ISO-8601 timestamps sort chronologically.
        $lastmods = [];

        foreach ($items as $item) {
            $group = $item[3];

            if (! isset($lastmods[$group]) || $item[1] > $lastmods[$group]) {
                $lastmods[$group] = $item[1];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($lastmods as $group => $lastmod) {
            $loc = htmlspecialchars($siteUrl . '/sitemap-' . $group . '.xml', ENT_QUOTES | ENT_XML1, 'UTF-8');
            $xml .= '<sitemap><loc>' . $loc . '</loc><lastmod>' . $lastmod . '</lastmod></sitemap>';
        }

        $xml .= '</sitemapindex>';

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
                'sitemap_priority' => (float) $entry->sitemap_priority,
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
