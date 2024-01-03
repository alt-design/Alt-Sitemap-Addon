<?php namespace AltDesign\AltSitemap\Helpers;

use Statamic\Fields\BlueprintRepository;
use Statamic\Facades\YAML;
use Statamic\Filesystem\Manager;

/**
 * Class Data
 *
 * @package  AltDesign\AltSitemap
 * @author   Ben Harvey <ben@alt-design.net>, Natalie Higgins <natalie@alt-design.net>
 * @license  Copyright (C) Alt Design Limited - All Rights Reserved - licensed under the MIT license
 * @link     https://alt-design.net
 */
class Data
{
    /**
     * @var
     */
    public $type;

    /**
     * @var Manager - The manager class for Statamic
     */
    public $manager;

    /**
     * @var mixed|null
     */
    public $currentFile;

    /**
     * @var array
     */
    public $data;

    /**
     * Data constructor.
     *
     * @param $type
     */
    public function __construct($type)
    {
        $this->type = $type;
        // New up Statamic File Manager
        $this->manager = new Manager();
        // Grab the current file we're working with
        $this->currentFile = $this->manager->disk()->get('content/alt-sitemap/' . $this->type . '.yaml');
        $this->data = Yaml::parse($this->currentFile);
    }

    /**
     * Gets data from the Yaml file by key.
     *
     * @param $key
     * @return mixed|null
     */
    public function get($key)
    {
        return $this->data[$key] ?? null;
    }

    public function getBlueprint($default = false)
    {
        if($default) {
            return with(new BlueprintRepository)->setDirectory(__DIR__ . '/../../resources/blueprints')->find($this->type);
        }

        return false;
    }

    /**
     * Sets data in the Yaml file by key.
     *
     * @param $key
     * @param $value
     * @return void
     */
    public function set($key, $value)
    {
        $this->data[$key] = $value;

        Yaml::dump($this->data, $this->currentFile);
    }

    /**
     * Returns all data from the Yaml file.
     *
     * @return array
     */
    public function all()
    {
        return $this->data;
    }

    /**
     * Sets all data in the Yaml file.
     *
     * @param $data
     * @return void
     */
    public function setAll($data)
    {
        $this->data = $data;

        $this->manager->disk()->put('content/alt-sitemap/' . $this->type . '.yaml', Yaml::dump($this->data));
    }

}
