<?php
/**
 * CacheStore — 缓存存储抽象接口
 *
 * Phase 2 核心抽象：统一 MarketDataService 和 FundService 的缓存逻辑，
 * 支持 FileCacheStore（开发/fallback）和 RedisCacheStore（生产推荐）
 */

interface CacheStore
{
    /**
     * 读取缓存
     *
     * @param string $key 缓存键
     * @return array|null 缓存数据数组，未命中返回 null
     */
    public function get(string $key): ?array;

    /**
     * 写入缓存
     *
     * @param string $key   缓存键
     * @param array  $data  缓存数据（必须包含 cached_at 时间戳）
     * @param int    $ttl   生存时间（秒）
     */
    public function set(string $key, array $data, int $ttl): void;

    /**
     * 删除缓存
     *
     * @param string $key 缓存键
     */
    public function delete(string $key): void;

    /**
     * 读取过期但仍存在的 stale 缓存（用于降级）
     *
     * @param string $key 缓存键
     * @return array|null stale 数据，不存在返回 null
     */
    public function getStale(string $key): ?array;

    /**
     * 获取分布式锁（防缓存击穿 per-key mutex）
     *
     * @param string $lockKey 锁键
     * @param int    $timeout 锁超时（秒）
     * @return bool 是否获取成功
     */
    public function acquireLock(string $lockKey, int $timeout = 5): bool;

    /**
     * 释放分布式锁
     *
     * @param string $lockKey 锁键
     */
    public function releaseLock(string $lockKey): void;

    /**
     * 返回存储后端名称（用于日志和 meta 标记）
     */
    public function backendName(): string;

    /**
     * 健康检查
     */
    public function ping(): bool;
}
