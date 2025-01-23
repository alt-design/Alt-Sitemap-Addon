<?php namespace AltDesign\AltSitemap\Events;

use Statamic\Events;
use Statamic\Facades\Blink;
use Statamic\Fields\BlueprintRepository;
use Statamic\Fields\Blueprint;
use Statamic\Events\TermBlueprintFound;

/**
 * Class Sitemap
 *
 * @package  AltDesign\AltSitemap
 * @author   Ben Harvey <ben@alt-design.net>, Natalie Higgins <natalie@alt-design.net>
 * @license  Copyright (C) Alt Design Limited - All Rights Reserved - licensed under the MIT license
 * @link     https://alt-design.net
 */
class Sitemap
{

    /**
     * Sets the events to listen for
     *
     * @var string[]
     */
    protected $events = [
        Events\EntryBlueprintFound::class => 'addSitemapData',
        Events\TermBlueprintFound::class => 'addSitemapData',
    ];

    /**
     * Subscribe to events
     *
     * @param $events
     * @return void
     */
    public function subscribe($events)
    {
        $events->listen(Events\EntryBlueprintFound::class, self::class.'@'.'addSitemapData');
        $events->listen(Events\TermBlueprintFound::class, self::class.'@'.'addSitemapData');
    }

    /**
     * Adds the Sitemap fields to the blueprint
     *
     * @param $event
     * @return void
     */
    public function addSitemapData($event)
    {
        // Grab the old directory just in case
        $oldDirectory = Blueprint::directory();

        $blueprint = with(new BlueprintRepository)->setDirectory(__DIR__ . '/../../resources/blueprints')->find('sitemap');

        // Check if they've set the event settings, continue if not
        if(!empty($event->blueprint)) {
            $blueprintReady = $event->blueprint->contents();
            $blueprintReady['tabs'] = array_merge($blueprintReady['tabs'], $blueprint->contents()['tabs']);
        } else {
            $blueprintReady = $blueprint->contents();
        }


        // Set the contents
        Blink::forget("blueprint-contents-{$event->blueprint->namespace()}-{$event->blueprint->handle()}");
        $event->blueprint->setContents($blueprintReady);

        // Reset the directory to the old one
        if($oldDirectory) {
            Blueprint::setDirectory($oldDirectory);
        }
    }



}
