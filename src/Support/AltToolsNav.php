<?php

namespace AltDesign\AltSitemap\Support;

use Statamic\CP\Navigation\Nav;
use Statamic\CP\Navigation\NavItem;
use Statamic\Facades\User;

/**
 * Adds an Alt addon to its group in the Alt Tools section of the control panel nav.
 *
 * Every Alt addon ships an identical copy of this class, so the groups and their
 * icons come out the same whichever combination of addons a site has installed.
 * Change one copy and the rest must change with it.
 */
class AltToolsNav
{
    public const SECTION = 'Alt Tools';

    /**
     * Each group's label, then its Statamic 6 and Statamic 4/5 icon names.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const GROUPS = [
        'seo' => ['SEO', 'search-magnifying-glass', 'seo-search-graph'],
        'spam' => ['Spam', 'mail-check', 'email-utility'],
        'privacy' => ['Privacy & security', 'security-lock', 'shield-key'],
        'editing' => ['Editing', 'pencil', 'content-writing'],
    ];

    /**
     * Add a page to a group, when the current user is allowed to see it.
     *
     * @param  string  $group  A key of GROUPS.
     * @param  string|array{0: string, 1: mixed}  $route  Control panel route name, or the name and its parameters.
     * @param  string|null  $permission  Null when every control panel user may see the page.
     * @param  array{0: string, 1: string}  $icons  Statamic 6 and Statamic 4/5 icon names.
     */
    public static function add(Nav $nav, string $group, string $label, string|array $route, ?string $permission, array $icons): void
    {
        if ($permission && ! User::current()?->can($permission)) {
            return;
        }

        [$groupLabel, $groupIcon, $legacyGroupIcon] = self::GROUPS[$group];

        $item = $nav->item($label)->route(...(array) $route)->icon(self::icon(...$icons));

        if ($permission) {
            $item->can($permission);
        }

        $parent = $nav->findOrCreate(self::SECTION, $groupLabel)->icon(self::icon($groupIcon, $legacyGroupIcon));

        $children = collect($parent->children() ?? [])
            ->push($item)
            ->sortBy(fn (NavItem $child) => $child->display())
            ->values();

        $parent->url($children->first()->url())->children($children->all());
    }

    /**
     * Pick the icon name that exists in the installed Statamic's icon set.
     *
     * Statamic 4 and 5 keep their nav icons in an icons/light folder that
     * Statamic 6 does not have, and an unknown name fails to render.
     *
     * @param  string  $icon  Statamic 6 icon name.
     * @param  string  $legacyIcon  Statamic 4/5 icon name.
     */
    public static function icon(string $icon, string $legacyIcon): string
    {
        return is_dir(statamic_path('resources/svg/icons/light')) ? $legacyIcon : $icon;
    }
}
