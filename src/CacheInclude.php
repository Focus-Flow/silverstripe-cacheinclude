<?php

namespace Heyday\CacheInclude;

use Doctrine\Common\Cache\CacheProvider;
use Heyday\CacheInclude\Configs\ConfigInterface;
use Heyday\CacheInclude\KeyCreators\KeyCreatorInterface;
use Heyday\CacheInclude\KeyCreators\KeyInformationProviderInterface;
use Heyday\CacheInclude\Processors\ProcessorInterface;
use RuntimeException;
use Psr\Log\LoggerInterface;

/**
 * Class CacheInclude
 * @package Heyday\CacheInclude
 */
class CacheInclude
{
    /**
     * @var string
     */
    public static $WRITE_LOCK_SUFFIX = ".lock";
    /**
    * @var int
    */
    public static  $LOCK_EXPIRE_TIME = 30;
    /**
     * The instance of doctrine cache that is used for caching
     * @var \Doctrine\Common\Cache\CacheProvider
     */
    protected $cache;
    /**
     * The config for the cache
     * @var ConfigInterface
     */
    protected $config;
    /**
     * Whether the cache is enable or skipped
     * @var bool
     */
    protected $enabled = true;
    /**
     * When this is set to true expire every cache that is requested
     * @var bool
     */
    protected $forceExpire = false;
    /**
     * The default config
     * @var array
     */
    protected $defaultConfig = array(
        'context' => 'no',
        'member' => false,
        'expires' => false
    );
    /**
     * An optional Logger
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @param CacheProvider   $cache
     * @param ConfigInterface $config
     * @param bool            $forceExpire
     */
    public function __construct(
        CacheProvider $cache,
        ConfigInterface $config,
        $forceExpire = false
    )
    {
        $this->cache = $cache;
        $this->config = $config;
        $this->forceExpire = $forceExpire;
    }

    /**
     * Set the cache to enabled or disabled
     * @param $enabled
     */
    public function setEnabled($enabled)
    {
        $this->enabled = (boolean) $enabled;
    }

    /**
     * Return whether the cache is enabled or disabled
     * @return bool
     */
    public function getEnabled()
    {
        return $this->enabled;
    }

    /**
     * Set the default config
     * @param $config
     */
    public function setDefaultConfig($config)
    {
        $this->defaultConfig = $config;
    }

    /**
     * Return the default config
     * @return array
     */
    public function getDefaultConfig()
    {
        return $this->defaultConfig;
    }

    /**
     * Get the config
     * @return ConfigInterface
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Set the force expiry flag
     * @param $forceExpire
     */
    public function setForceExpire($forceExpire)
    {
        $this->forceExpire = (boolean) $forceExpire;
    }

    /**
     * Get the force expire flag
     * @return bool
     */
    public function getForceExpire()
    {
        return $this->forceExpire;
    }

    /**
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return \Psr\Log\LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Get the default config combined with the provided config
     * @param $name
     * @return mixed
     * @throws \RuntimeException
     */
    public function getCombinedConfig($name)
    {
        $config = $this->defaultConfig;
        if (isset($this->config[$name]) && is_array($this->config[$name])) {
            $config = $this->config[$name] + $config;
        } else {
            throw new RuntimeException("Config '$name' doesn't exist, please check your config");
        }

        return $config;
    }

    /**
     * Prepare a key from key parts
     * @param  array  $keyParts
     * @return string
     */
    protected function prepareKey(array $keyParts)
    {
        return implode('.', $keyParts);
    }

    /**
     *
     * @param string $name
     * @param string $processor
     * @param \Heyday\CacheInclude\KeyCreators\KeyCreatorInterface $keyCreator
     * @throws \InvalidArgumentException
     * @return mixed
     */
    public function process($name, $processor, KeyCreatorInterface $keyCreator)
    {
        if (!$processor instanceof ProcessorInterface && !is_callable($processor)) {
            throw new \InvalidArgumentException(
                'The argument $processor must be an instance of ProcessorInterface or a callable'
            );
        }

        if (!$this->enabled) {
            return $processor($name);
        }

        $config = $this->getCombinedConfig($name);

        $key = $this->getKey($name, $keyCreator, $config);

        if ($this->forceExpire) {
            $result = $this->forceExpire($processor, $name, $key);
            $type = "EXPIRE";
        } elseif ($this->cache->contains($key)) {
            if ($this->keyInIndex($key, $name)) {
                $result = $this->cache->fetch($key);
                $type = "HIT";
            } else {
                $result = $this->cacheSave($processor, $keyCreator, $config, $name, $key);
                $type = "INDEX_CORRUPTION";
            }
        } else {
            $result = $this->cacheSave($processor, $keyCreator, $config, $name, $key);
            $type = "MISS";
        }

        $this->log($type, $name, $key);

        return $result;
    }

    /**
     * @param string $processor
     * @param string $name
     * @param string $key
     *
     * @return mixed
     */

    private function forceExpire($processor, $name, $key)
    {
      $result = $processor($name);

      if (!$this->checkLockForName($name)) {
        $this->cache->delete($key);
        $this->removeStoredKey($name, $key);
        $this->releaseLockForName($name);
      }

      return $result;
    }

  /**
   * @param $processor
   * @param $keyCreator
   * @param $config
   * @param $name
   * @param $key
   *
   * @return mixed
   */

    private function cacheSave($processor, $keyCreator, $config, $name, $key)
    {
        $result = $processor($name);

        if(!$this->checkLockForName($name)){
            $this->createLockForName($name);
            $this->cache->save(
              $key,
              $result,
              $this->getExpiry($config)
            );
            $this->addStoredKey($name, $key, $keyCreator);
            $this->releaseLockForName($name);
        }

        return $result;
    }

    /**
     * @param string $name
     * @param \Heyday\CacheInclude\KeyCreators\KeyCreatorInterface $keyCreator
     * @return mixed
     */
    public function get($name, KeyCreatorInterface $keyCreator)
    {
        $key = $this->getKey($name, $keyCreator);
        $result = $this->cache->fetch($key);

        if ($result) {
            if ($this->keyInIndex($key, $name)) {
                $this->log('HIT', $name, $key);
            } else {
                $result = false;
                $this->log('INDEX_CORRUPTION', $name, $key);
            }
        } else {
            $this->log('MISS', $name, $key);
        }

        return $result;
    }

    /**
     * @param string $name
     * @param string $result
     * @param \Heyday\CacheInclude\KeyCreators\KeyCreatorInterface $keyCreator
     * @return void
     */
    public function set($name, $result, KeyCreatorInterface $keyCreator)
    {
        if (!$this->enabled) {
            return;
        }

        $config = $this->getCombinedConfig($name);

        $this->cache->save(
            $key = $this->getKey($name, $keyCreator, $config),
            $result,
            $this->getExpiry($config)
        );

        $this->addStoredKey($name, $key, $keyCreator);
    }

    /**
     * @param string $name
     * @param string $key
     * @param \Heyday\CacheInclude\KeyCreators\KeyCreatorInterface $keyCreator
     * @return void
     */
    protected function addStoredKey($name, $key, KeyCreatorInterface $keyCreator)
    {
        $keys = (array) $this->cache->fetch($name);
        if (!array_key_exists($key, $keys)) {
            if ($keyCreator instanceof KeyInformationProviderInterface) {
                $keys[$key] = $keyCreator->getKeyInformation();
            } else {
                $keys[$key] = true;
            }
            $this->cache->save($name, $keys);
        }
    }

    /**
     * @param string $name
     * @param string $key
     */
    protected function removeStoredKey($name, $key)
    {
        $keys = (array) $this->cache->fetch($name);
        if (array_key_exists($key, $keys)) {
            unset($keys[$key]);
            $this->cache->save($name, $keys);
        }
    }

  /**
   * @param string $name
   *
   * @return bool
   */
    public function createLockForName($name)
    {
     return $this->cache->save($name . self::$WRITE_LOCK_SUFFIX, "",
        self::$LOCK_EXPIRE_TIME);
    }

  /**
   * @param string $name
   *
   * @return bool
   */

    public function releaseLockForName($name)
    {
      return $this->cache->delete($name . self::$WRITE_LOCK_SUFFIX);
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function checkLockForName($name)
    {
      return $this->cache->contains($name . self::$WRITE_LOCK_SUFFIX);
    }

    /**
     * @param $name
     */
    public function flushByName($name)
    {
        $keys = (array) $this->cache->fetch($name);
        foreach (array_keys($keys) as $key) {
            $this->cache->delete($key);
        }
        $this->cache->save($name, array());
    }

    /**
     *
     */
    public function flushAll()
    {
        $this->cache->flushAll();
    }

    /**
     * @param $config
     * @return string|int
     */
    protected function getExpiry($config)
    {
        if (isset($config['expires']) && is_string($config['expires'])) {
            $expires = strtotime($config['expires']) - time();
        } else {
            $expires = 0;
        }

        return $expires;
    }

    /**
     * @param $name
     * @param  KeyCreatorInterface $keyCreator
     * @param  array               $config
     * @return string
     */
    protected function getKey($name, KeyCreatorInterface $keyCreator, array $config = null)
    {
        return $this->prepareKey(
            $keyCreator->getKey(
                $name,
                $config ?: $this->getCombinedConfig($name)
            )
        );
    }

    /**
     * Log an event
     * @param $type
     * @param $name
     * @param $key
     */
    protected function log($type, $name, $key)
    {
        if ($this->logger) {
            $this->logger->info(sprintf("[%s] cacheinclude '%s' with key '%s'", $type, $name, $key));
        }
    }

    /**
     * Check if `$key` is in `$index`
     *
     * This is to ensure that multiple concurrent writes to index don't cause
     * using stale keys in cache.
     *
     * @param string $key
     * @param string $index
     *
     * @return bool
     */
    protected function keyInIndex($key, $index)
    {
        $keys = array_keys($this->cache->fetch($index));
        if (!is_array($keys)) {
            $keys = [];
        }
        return in_array($key, $keys);
    }
}
