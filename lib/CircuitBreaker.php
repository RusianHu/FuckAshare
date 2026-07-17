<?php
/**
 * CircuitBreaker — 熔断器 + 结构化日志
 *
 * 雪球接口连续失败时自动熔断，避免雪球故障拖垮整体响应时间
 * Phase 2: 支持 Redis backend，独立数据源熔断器
 *
 * 日志写入系统临时目录，不提交到版本库
 */

require_once __DIR__ . '/CacheStoreFactory.php';
require_once __DIR__ . '/AppConfig.php';

class CircuitBreaker
{
    const STATE_CLOSED    = 'closed';     // 正常
    const STATE_OPEN      = 'open';       // 熔断
    const STATE_HALF_OPEN = 'half_open';  // 半开（试探）

    /** @var string 数据源名称 */
    private $source;

    /** @var int 连续失败阈值 */
    private $failureThreshold;

    /** @var int 熔断冷却时间（秒） */
    private $cooldown;

    /** @var string 状态文件路径（文件 fallback） */
    private $stateFile;

    /** @var array 当前状态 */
    private $state;

    /** @var string 日志目录 */
    private static $logDir;

    /** @var bool 是否使用 Redis */
    private $useRedis = false;

    // ── 数据源默认熔断配置 ──

    /** @var array 各数据源的默认配置 */
    const SOURCE_DEFAULTS = [
        'xueqiu'     => ['failureThreshold' => 3,  'cooldown' => 60],
        'eastmoney'  => ['failureThreshold' => 5,  'cooldown' => 30],
        'ashare'     => ['failureThreshold' => 3,  'cooldown' => 60],
        'fund'       => ['failureThreshold' => 5,  'cooldown' => 30],
        'csindex'    => ['failureThreshold' => 3,  'cooldown' => 60],
        'eastmoney_dividend' => ['failureThreshold' => 3, 'cooldown' => 60],
        'eastmoney_fund_dividend' => ['failureThreshold' => 3, 'cooldown' => 60],
        'eastmoney_news' => ['failureThreshold' => 3, 'cooldown' => 60],
        'eastmoney_f10_news' => ['failureThreshold' => 3, 'cooldown' => 60],
        'eastmoney_fast_news' => ['failureThreshold' => 3, 'cooldown' => 60],
        'google_news_rss' => ['failureThreshold' => 3, 'cooldown' => 120],
        'eastmoney_fund_announcements' => ['failureThreshold' => 3, 'cooldown' => 60],
        'eastmoney_announcements' => ['failureThreshold' => 3, 'cooldown' => 60],
    ];

    public function __construct(string $source, int $failureThreshold = 0, int $cooldown = 0)
    {
        $this->source = $source;

        // 使用数据源默认配置（如果未显式指定）
        $defaults = self::SOURCE_DEFAULTS[$source] ?? ['failureThreshold' => 3, 'cooldown' => 60];
        $configured = AppConfig::get("circuit_breaker.{$source}", []);
        if (is_array($configured)) {
            $defaults['failureThreshold'] = $configured['failure_threshold'] ?? $defaults['failureThreshold'];
            $defaults['cooldown'] = $configured['cooldown'] ?? $defaults['cooldown'];
        }
        $this->failureThreshold = $failureThreshold > 0 ? $failureThreshold : (int)$defaults['failureThreshold'];
        $this->cooldown         = $cooldown > 0 ? $cooldown : (int)$defaults['cooldown'];

        // 检测 Redis 可用性
        $store = CacheStoreFactory::getInstance();
        if ($store instanceof RedisCacheStore) {
            $redis = $store->redis();
            if ($redis !== null) {
                $this->useRedis = true;
            }
        }

        // 文件 fallback 路径
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_circuit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $this->stateFile = $dir . DIRECTORY_SEPARATOR . md5($source) . '.json';

        $this->loadState();
    }

    /**
     * 是否允许请求通过
     */
    public function allow(): bool
    {
        if ($this->state['state'] === self::STATE_CLOSED) {
            return true;
        }

        if ($this->state['state'] === self::STATE_OPEN) {
            if (time() - $this->state['opened_at'] >= $this->cooldown) {
                $this->state['state'] = self::STATE_HALF_OPEN;
                $this->saveState();
                self::log($this->source, 'half_open', "熔断器进入半开状态，允许试探请求");
                return true;
            }
            return false;
        }

        return true;
    }

    /**
     * 记录成功
     */
    public function success(): void
    {
        if ($this->state['state'] !== self::STATE_CLOSED) {
            self::log($this->source, 'closed', "熔断器恢复，数据源恢复正常");
        }
        $this->state['state']          = self::STATE_CLOSED;
        $this->state['failures']       = 0;
        $this->state['last_success']   = time();
        $this->saveState();
    }

    /**
     * 记录失败
     */
    public function failure(string $reason = ''): void
    {
        $this->state['failures']++;
        $this->state['last_failure'] = time();
        $this->state['last_reason']  = $reason;

        if ($this->state['state'] === self::STATE_HALF_OPEN) {
            $this->state['state']     = self::STATE_OPEN;
            $this->state['opened_at'] = time();
            self::log($this->source, 'open', "半开试探失败，重新熔断: {$reason}");
        } elseif ($this->state['failures'] >= $this->failureThreshold) {
            $this->state['state']     = self::STATE_OPEN;
            $this->state['opened_at'] = time();
            self::log($this->source, 'open', "连续 {$this->state['failures']} 次失败，触发熔断: {$reason}");
        }

        $this->saveState();
    }

    /**
     * 获取当前状态
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * 熔断器是否处于熔断状态
     */
    public function isOpen(): bool
    {
        return $this->state['state'] === self::STATE_OPEN;
    }

    // ── 内部 ──

    private function loadState(): void
    {
        // 优先从 Redis 加载
        if ($this->useRedis) {
            try {
                $store = CacheStoreFactory::getInstance();
                $redis = $store->redis();
                $data = $redis->get('cb:' . $this->source);
                if (is_array($data)) {
                    $this->state = $data;
                    return;
                }
            } catch (\Exception $e) {
                // Redis 读取失败，降级到文件
            }
        }

        // 文件 fallback
        if (file_exists($this->stateFile)) {
            $data = @json_decode(file_get_contents($this->stateFile), true);
            if (is_array($data)) {
                $this->state = $data;
                return;
            }
        }

        $this->state = [
            'source'        => $this->source,
            'state'         => self::STATE_CLOSED,
            'failures'      => 0,
            'opened_at'     => 0,
            'last_failure'  => 0,
            'last_success'  => 0,
            'last_reason'   => '',
        ];
    }

    private function saveState(): void
    {
        // 优先写 Redis
        if ($this->useRedis) {
            try {
                $store = CacheStoreFactory::getInstance();
                $redis = $store->redis();
                $redis->setex('cb:' . $this->source, $this->cooldown + 300, $this->state);
            } catch (\Exception $e) {
                // Redis 写入失败，降级到文件
            }
        }

        // 文件 fallback（始终写入，确保双写一致性）
        @file_put_contents($this->stateFile, json_encode($this->state, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    // ── 结构化日志 ──

    /**
     * 写入结构化日志
     */
    public static function log(string $source, string $event, string $message, array $extra = []): void
    {
        $dir = self::getLogDir();
        $file = $dir . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';

        $entry = [
            'ts'      => date('c'),
            'source'  => $source,
            'event'   => $event,
            'message' => $message,
        ];
        if (!empty($extra)) {
            $entry = array_merge($entry, $extra);
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function getLogDir(): string
    {
        if (self::$logDir === null) {
            self::$logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_logs';
            if (!is_dir(self::$logDir)) {
                @mkdir(self::$logDir, 0700, true);
            }
        }
        return self::$logDir;
    }
}
