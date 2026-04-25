<?php
/**
 * RedisCacheStore — 基于 Redis 的缓存实现（生产推荐）
 *
 * 使用 phpredis 扩展。Redis 不可用时通过 Factory 自动降级到 FileCacheStore。
 * 支持 stale 缓存、per-key mutex（SET NX EX）、negative cache。
 */

require_once __DIR__ . '/CacheStore.php';

class RedisCacheStore implements CacheStore
{
    /** @var \Redis */
    private $redis;

    /** @var string key 前缀 */
    private $prefix;

    /** @var bool 连接是否就绪 */
    private $ready = false;

    /** @var array<string,string> lockKey => token */
    private $lockTokens = [];

    /**
     * @param array $config [
     *   'host'    => '127.0.0.1',
     *   'port'    => 6379,
     *   'password'=> '',
     *   'db'      => 0,
     *   'prefix'  => 'fa:',   // fuckashare 缩写
     *   'timeout' => 2.0,
     * ]
     */
    public function __construct(array $config = [])
    {
        $this->prefix = $config['prefix'] ?? 'fa:';

        if (!class_exists('\\Redis')) {
            $this->ready = false;
            return;
        }

        try {
            $this->redis = new \Redis();
            $host    = $config['host'] ?? '127.0.0.1';
            $port    = $config['port'] ?? 6379;
            $timeout = $config['timeout'] ?? 2.0;

            if (!$this->redis->connect($host, $port, $timeout)) {
                $this->ready = false;
                return;
            }

            $password = $config['password'] ?? '';
            if ($password !== '' && !$this->redis->auth($password)) {
                $this->ready = false;
                return;
            }

            $db = $config['db'] ?? 0;
            if ($db > 0) {
                $this->redis->select($db);
            }

            $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);
            $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);

            $this->ready = true;
        } catch (\Exception $e) {
            $this->ready = false;
        }
    }

    public function get(string $key): ?array
    {
        if (!$this->ready) return null;

        try {
            $data = $this->redis->get("cache:{$key}");
            if (!is_array($data)) return null;

            // Redis TTL 由服务端管理，不需要客户端检查
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function set(string $key, array $data, int $ttl): void
    {
        if (!$this->ready) return;

        try {
            $data['cached_at'] = $data['cached_at'] ?? time();
            $data['_ttl'] = $ttl;

            // 主缓存：精确 TTL
            $this->redis->setex("cache:{$key}", $ttl, $data);

            // Stale 缓存：TTL + 600s，用于过期后降级读取
            $this->redis->setex("stale:{$key}", $ttl + 600, $data);
        } catch (\Exception $e) {
            // 静默失败，不影响主流程
        }
    }

    public function delete(string $key): void
    {
        if (!$this->ready) return;

        try {
            $this->redis->del("cache:{$key}", "stale:{$key}");
        } catch (\Exception $e) {
            // 静默失败
        }
    }

    public function getStale(string $key): ?array
    {
        if (!$this->ready) return null;

        try {
            $data = $this->redis->get("stale:{$key}");
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function acquireLock(string $lockKey, int $timeout = 5): bool
    {
        if (!$this->ready) return false;

        try {
            $lockKey = "lock:{$lockKey}";
            $token = uniqid('', true);

            // SET NX EX 原子获取锁
            $ok = $this->redis->set($lockKey, $token, ['NX', 'EX' => $timeout]);
            if ($ok !== false) {
                $this->lockTokens[$lockKey] = $token;
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function releaseLock(string $lockKey): void
    {
        if (!$this->ready) return;

        try {
            $redisKey = "lock:{$lockKey}";
            if (!isset($this->lockTokens[$redisKey])) {
                return;
            }

            $token = $this->lockTokens[$redisKey];
            $this->redis->watch($redisKey);
            if ($this->redis->get($redisKey) === $token) {
                $this->redis->multi();
                $this->redis->del($redisKey);
                $this->redis->exec();
            } else {
                $this->redis->unwatch();
            }
            unset($this->lockTokens[$redisKey]);
        } catch (\Exception $e) {
            // 静默失败
        }
    }

    public function backendName(): string
    {
        return 'redis';
    }

    public function ping(): bool
    {
        if (!$this->ready) return false;

        try {
            return $this->redis->ping() !== false;
        } catch (\Exception $e) {
            $this->ready = false;
            return false;
        }
    }

    /**
     * 获取原始 Redis 实例（供限流/熔断等高级用途使用）
     */
    public function redis(): ?\Redis
    {
        return $this->ready ? $this->redis : null;
    }
}
