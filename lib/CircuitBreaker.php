<?php
/**
 * CircuitBreaker — 熔断器 + 结构化日志
 *
 * 雪球接口连续失败时自动熔断，避免雪球故障拖垮整体响应时间
 * 日志写入系统临时目录，不提交到版本库
 */

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

    /** @var string 状态文件路径 */
    private $stateFile;

    /** @var array 当前状态 */
    private $state;

    /** @var string 日志目录 */
    private static $logDir;

    public function __construct(string $source, int $failureThreshold = 3, int $cooldown = 60)
    {
        $this->source           = $source;
        $this->failureThreshold = $failureThreshold;
        $this->cooldown         = $cooldown;

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
            // 检查冷却时间
            if (time() - $this->state['opened_at'] >= $this->cooldown) {
                $this->state['state'] = self::STATE_HALF_OPEN;
                $this->saveState();
                self::log($this->source, 'half_open', "熔断器进入半开状态，允许试探请求");
                return true;
            }
            return false;
        }

        // HALF_OPEN: 允许一次试探
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
            // 半开状态下失败 → 重新熔断
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

    // ── 内部 ──

    private function loadState(): void
    {
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
