<?php
/**
 * FileCacheStore — 基于文件的缓存实现（开发环境 fallback）
 *
 * 从 MarketDataService / FundService 中提取并统一的文件缓存逻辑。
 * 支持 stale 缓存读取、per-key mutex（文件锁）、negative cache。
 */

require_once __DIR__ . '/CacheStore.php';

class FileCacheStore implements CacheStore
{
    /** @var string 缓存目录 */
    private $cacheDir;

    /** @var string 锁目录 */
    private $lockDir;

    /** @var resource[] 活跃锁文件句柄 */
    private $lockHandles = [];

    public function __construct(string $cacheDir = '')
    {
        $this->cacheDir = $cacheDir ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_cache';
        $this->lockDir  = $this->cacheDir . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0700, true);
        }
        if (!is_dir($this->lockDir)) {
            @mkdir($this->lockDir, 0700, true);
        }
    }

    public function get(string $key): ?array
    {
        $file = $this->cachePath($key);
        $content = @file_get_contents($file);
        if ($content === false) return null;

        $data = json_decode($content, true);
        if (!is_array($data)) return null;

        // 检查 TTL
        $ttl = $data['_ttl'] ?? 60;
        if (time() - ($data['cached_at'] ?? 0) > $ttl) {
            // 过期但保留 stale 数据（不立即删除，留给 getStale）
            return null;
        }

        return $data;
    }

    public function set(string $key, array $data, int $ttl): void
    {
        $file = $this->cachePath($key);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        $data['_ttl'] = $ttl;
        $data['cached_at'] = $data['cached_at'] ?? time();

        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    public function delete(string $key): void
    {
        @unlink($this->cachePath($key));
    }

    public function getStale(string $key): ?array
    {
        $file = $this->cachePath($key);
        $content = @file_get_contents($file);
        if ($content === false) return null;

        $data = json_decode($content, true);
        if (!is_array($data)) return null;

        // 检查 stale 窗口：缓存过期不超过 10 分钟则返回 stale
        $ttl = $data['_ttl'] ?? 60;
        $age = time() - ($data['cached_at'] ?? 0);
        if ($age > $ttl && $age < $ttl + 600) {
            return $data;
        }

        // 超过 stale 窗口则清理
        if ($age >= $ttl + 600) {
            @unlink($file);
        }
        return null;
    }

    public function acquireLock(string $lockKey, int $timeout = 5): bool
    {
        $lockFile = $this->lockDir . DIRECTORY_SEPARATOR . md5($lockKey) . '.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) return false;

        // 非阻塞尝试获取锁
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            // 写入时间戳用于超时检测
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)time());
            fflush($fp);
            $this->lockHandles[$lockKey] = $fp;
            return true;
        }

        // 锁被占用。flock 会在持有进程退出时由系统释放，这里不删除锁文件，
        // 避免慢请求仍持锁时被其它请求误判超时并击穿上游。
        fclose($fp);
        return false;
    }

    public function releaseLock(string $lockKey): void
    {
        if (isset($this->lockHandles[$lockKey])) {
            flock($this->lockHandles[$lockKey], LOCK_UN);
            fclose($this->lockHandles[$lockKey]);
            unset($this->lockHandles[$lockKey]);
        }
    }

    public function backendName(): string
    {
        return 'file';
    }

    public function ping(): bool
    {
        return is_dir($this->cacheDir) && is_writable($this->cacheDir);
    }

    private function cachePath(string $key): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . md5($key) . '.json';
    }
}
