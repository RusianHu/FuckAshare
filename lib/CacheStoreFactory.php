<?php
/**
 * CacheStoreFactory — 缓存存储工厂
 *
 * 优先创建 RedisCacheStore，Redis 不可用时自动降级到 FileCacheStore。
 * 全局单例管理，避免每个请求重复创建连接。
 */

require_once __DIR__ . '/CacheStore.php';
require_once __DIR__ . '/FileCacheStore.php';
require_once __DIR__ . '/RedisCacheStore.php';
require_once __DIR__ . '/AppConfig.php';

class CacheStoreFactory
{
    /** @var CacheStore|null 全局单例 */
    private static $instance;

    /** @var array Redis 配置 */
    private static $redisConfig = [];

    /** @var bool 是否已尝试连接 Redis */
    private static $triedRedis = false;

    /** @var bool Redis 是否可用 */
    private static $redisAvailable = false;

    /**
     * 配置 Redis 连接参数
     *
     * @param array $config Redis 配置
     */
    public static function configureRedis(array $config): void
    {
        self::$redisConfig = $config;
        self::$triedRedis = false;
        self::$instance = null;
    }

    /**
     * 获取全局 CacheStore 实例
     *
     * @return CacheStore
     */
    public static function getInstance(): CacheStore
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // 尝试 Redis
        if (!self::$triedRedis) {
            self::$triedRedis = true;
            if (empty(self::$redisConfig)) {
                $configuredRedis = AppConfig::get('redis', []);
                if (is_array($configuredRedis)) {
                    self::$redisConfig = $configuredRedis;
                }
            }
            if (!empty(self::$redisConfig) || class_exists('\\Redis')) {
                $redis = new RedisCacheStore(self::$redisConfig);
                if ($redis->ping()) {
                    self::$redisAvailable = true;
                    self::$instance = $redis;
                    return $redis;
                }
            }
        }

        // Fallback 到文件缓存
        self::$instance = new FileCacheStore();
        return self::$instance;
    }

    /**
     * 强制使用文件缓存（测试或禁用 Redis 时使用）
     */
    public static function useFileStore(string $cacheDir = ''): CacheStore
    {
        self::$instance = new FileCacheStore($cacheDir);
        return self::$instance;
    }

    /**
     * 强制使用 Redis（配置已知正确时使用）
     */
    public static function useRedisStore(array $config = []): CacheStore
    {
        $redis = new RedisCacheStore($config);
        self::$instance = $redis;
        self::$redisAvailable = $redis->ping();
        return $redis;
    }

    /**
     * Redis 是否可用
     */
    public static function isRedisAvailable(): bool
    {
        return self::$redisAvailable;
    }

    /**
     * 重置单例（测试用）
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$triedRedis = false;
        self::$redisAvailable = false;
        AppConfig::reset();
    }
}
