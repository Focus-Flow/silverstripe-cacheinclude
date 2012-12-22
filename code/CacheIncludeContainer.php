<?php

class CacheIncludeContainer extends Pimple
{
    /**
     * Default config of properties
     * @var array
     */
    protected $config = array(
        //CacheCache
        'cachecache.class'                        => '\CacheCache\Cache',
        'cachecache.options.namespace'            => 'cacheinclude',
        'cachecache.options.default_ttl'          => null,
        'cachecache.options.ttl_variation'        => 0,

        //CacheCache backend
        'cachecache_backend.class'                => '\CacheCache\Backends\File',

        //CacheInclude
        'cacheinclude.class'                      => 'CacheInclude'
        'cacheinclude.options.delayed_processing' => false,
        'cacheinclude.options.enabled'            => true,
        'cacheinclude.options.config'             => array(),
        'cacheinclude.options.force_expire'       => false,

        //CacheIncludeContext
        'cacheinclude_key_creator.class'          => 'CacheIncludeKeyCreator',

        //CacheIncludeProcessor
        'cacheinclude_processor.class'            => 'CacheIncludeProcessor'

    );
    /**
     * Holds user configured extensions of services
     * @var array
     */
    protected static $extensions = array();
    /**
     * Holds user configured shared services
     * @var array
     */
    protected static $shared = array();
    /**
     * Constructs the container and set up default services and properties
     */
    public function __construct()
    {

        //CacheCache
        $this['cachecache'] = $this->share(function ($c) {
            return new $c['cachecache.class'](
                $c['cachecache_backend'],
                $c['cachecache.options.namespace'],
                $c['cachecache.options.default_ttl'],
                $c['cachecache.options.ttl_variation']
            );
        });

        //CacheCache backend
        $this['cachecache_backend'] = $this->share(function ($c) {
            return new $c['cachecache_backend.class']($c['cachecache_backend.options']);
        });

        $this['cachecache_backend.options'] = array(
            'dir'            => __DIR__ . '/../cache',
            'file_extension' => '.cache'
        );

        //CacheInclude
        $this['cacheinclude'] = $this->share(function ($c) {
            $cacheinclude = new $c['cacheinclude.class'](
                $c['cachecache'],
                $c['cacheinclude_key_creator'],
                $c['cacheinclude.options.config'],
                $c['cacheinclude.options.force_expire']
            );
            if ($c->offsetExists('cacheinclude.options.delayed_processing')) {
                $cacheinclude->setDelayedProcessing($c['cacheinclude.options.delayed_processing']);
            }
            if ($c->offsetExists('cacheinclude.options.enabled')) {
                $cacheinclude->setEnabled($c['cacheinclude.options.enabled']);
            }
            if ($c->offsetExists('cacheinclude.options.extra_memory')) {
                $cacheinclude->setExtraMemory($c['cacheinclude.options.extra_memory']);
            }
            if ($c->offsetExists('cacheinclude.options.default_config')) {
                $cacheinclude->setDefaultConfig($c['cacheinclude.options.default_config']);
            }
            return $cacheinclude;
        });

        //CacheIncludeKeyCreator
        $this['cacheinclude_key_creator'] = $this->share(function ($c) {
            return new $c['cacheinclude_key_creator.class'];
        });

        //CacheIncludeProcessor
        $this['cacheinclude_processor'] = $this->share(function ($c) {
            return new $c['cacheinclude_processor.class'];
        });

        //Default config
        foreach (self::$config as $key => $value) {
            $this[$key] = $value;
        }

        //Extensions
        if (is_array(self::$extensions)) {
            foreach (self::$extensions as $value) {
                $this->extend($value[0], $value[1]);
            }
        }

        //Shared
        if (is_array(self::$shared)) {
            foreach (self::$shared as $value) {
                $this[$value[0]] = $this->share($value[1]);
            }
        }

    }
    /**
     * Alows the extending of already defined services by the user
     * @param string  $name      Name of service
     * @param Closure $extension Extending function
     */
    public static function addExtension($name, $extension)
    {
        self::$extensions[] = array($name, $extension);
    }
    /**
     * Allows the adding of a shared service by the user
     * @param string  $name   Name of service
     * @param Closure $shared The shared service function
     */
    public static function addShared($name, $shared)
    {
        self::$shared[] = array($name, $shared);
    }
    /**
     * Allows the addition to the default config by the user
     * @param array $config The extending config
     */
    public static function extendConfig($config)
    {
        if (is_array($config)) {
            self::$config = array_merge(self::$config, $config);
        }
    }

}