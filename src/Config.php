<?php

namespace G4\Config;

use Zend\Config\Reader\Ini as Reader;
use G4\Log\Writer;

class Config
{
    private $cachingEnabled = false;

    private $cachePath;

    private $data;

    private $path;

    private $sections;

    private $section;

    public function setPath($path)
    {
        $this->path = $path;
        return $this;
    }

    public function setSection($section)
    {
        $this->section = $section;
        return $this;
    }

    public function setCachingEnabled($flag)
    {
        $this->cachingEnabled = (bool) $flag;
        return $this;
    }

    public function setCachePath($cachePath)
    {
        $this->cachePath = (string) $cachePath;
        return $this;
    }

    private function formatCacheFilename()
    {
        $segments = array(
            __NAMESPACE__,
            __CLASS__,
            $this->section,
        );

        // section can be empty, so remove it
        $filename = md5(implode('~', array_filter($segments)));

        if(!is_writable($this->cachePath)) {
            throw new \Exception('Cache file path is not writable');
        }

        return $this->cachePath . $filename;
    }

    private function getFromCache()
    {
        $realCachePath = realpath($this->formatCacheFilename());

        if(false === $realCachePath) {
            return null;
        }

        if(!is_readable($realCachePath)) {
            throw new \Exception('Cache file path is not readable');
        }

        return require($realCachePath);
    }

    private function setToCache()
    {
        $realCachePath = $this->formatCacheFilename();

        $toSave = "<?php return \n" . var_export($this->data, true) . ';';

        if(!touch($realCachePath)) {
            throw new \Exception('Cache file path is not writable');
        }

        file_put_contents($realCachePath, $toSave);

        return $this;
    }

    private function process()
    {
        $path = realpath($this->path);

        if(false === $path || !is_readable($path)) {
            throw new \Exception('Configuration file is not readable');
        }

        $reader = new Reader();
        $this->data = $reader->fromFile($path);

        $this->data = null !== $this->section
            ? $this->getSection($this->section)
            : $this->mergeSections();

        return $this;
    }

    private function getSection($name)
    {
        $this->processSections();

        if(!array_key_exists($name, $this->sections)) {
            throw new \Exception("Section '{$name}' missing");
        }

        $extends = false;
        $parentData = array();

        if($this->sections[$name] !== null) {
            $extends = true;
            $func = __FUNCTION__;
            $parentData = $this->$func($this->sections[$name]);
        }

        $sectionName = $extends === true
            ? $name . ':' . $this->sections[$name]
            : $name;

        $sectionData = $this->data[$sectionName];

        $newData = array_replace_recursive($parentData, $sectionData);

        ksort($newData);

        return $newData;
    }

    private function processSections()
    {
        $tmp = array_keys($this->data);

        foreach($tmp as $item) {
            if(substr_count($item, ":") > 1) { }

            $segments = explode(':', $item);

            $first = trim(array_shift($segments));

            $this->sections[$first] = !empty($segments)
                ? trim(array_shift($segments))
                : null;
        }

        return $this;
    }

    private function getSectionName($item)
    {
        if(substr_count($item, ":") > 1) { }
        $segments = explode(':', $item);
        return $segments[0];
    }

    private function mergeSections()
    {
        $tmpData = [];
        foreach($this->data as $sectionName => $data){
            $name = $this->getSectionName($sectionName);
            $tmpData[$name] = $this->getSection($name);
        }
        return $tmpData;
    }

    public function getData($force = false)
    {
        if($force !== true && $this->cachingEnabled) {
            $this->data = $this->getFromCache();
        }

        if(null === $this->data) {
            $this->process();

            if($this->cachingEnabled) {
                $this->setToCache();
            }
        }

        return $this->data;
    }
}