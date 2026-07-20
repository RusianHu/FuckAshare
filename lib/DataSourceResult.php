<?php
/**
 * DataSourceResult — 统一成功、失败、兜底、缓存、上游状态结构
 */

class DataSourceResult
{
    const STATUS_SUCCESS          = 'success';
    const STATUS_EMPTY           = 'empty';
    const STATUS_UPSTREAM_ERROR  = 'upstream_error';
    const STATUS_CHALLENGE_BLOCKED = 'challenge_blocked';
    const STATUS_FALLBACK_USED   = 'fallback_used';
    const STATUS_CACHE_HIT       = 'cache_hit';
    const STATUS_NETWORK_ERROR   = 'network_error';
    const STATUS_PARSE_ERROR     = 'parse_error';
    const STATUS_TIMEOUT         = 'timeout';
    const STATUS_BUSINESS_ERROR  = 'business_error';

    /** @var bool */
    public $success;

    /** @var string 数据源标识: xueqiu / eastmoney / ashare */
    public $source;

    /** @var string action: quote / kline / hot_stock / screener / fundx */
    public $action;

    /** @var string 状态码 */
    public $status;

    /** @var mixed 数据 */
    public $data;

    /** @var string|null 错误码 */
    public $errorCode;

    /** @var string|null 错误消息 */
    public $errorMessage;

    /** @var array meta 信息 */
    public $meta;

    /**
     * 创建成功结果
     */
    public static function success(string $source, string $action, $data, array $meta = []): self
    {
        $r = new self();
        $r->success = true;
        $r->source  = $source;
        $r->action  = $action;
        $r->status  = self::STATUS_SUCCESS;
        $r->data    = $data;
        $r->meta    = array_merge([
            'provider_status' => 200,
            'cache'           => 'miss',
            'updated_at'      => date('c'),
            'fallback_trace'  => [],
        ], $meta);
        return $r;
    }

    /**
     * 创建失败结果
     */
    public static function error(string $source, string $action, string $errorCode, string $message, array $meta = []): self
    {
        $r = new self();
        $r->success      = false;
        $r->source       = $source;
        $r->action       = $action;
        $r->status       = $errorCode;
        $r->errorCode    = $errorCode;
        $r->errorMessage = $message;
        $r->data         = null;
        $r->meta         = array_merge([
            'provider_status' => 0,
            'cache'           => 'miss',
            'fallback_trace'  => [],
        ], $meta);
        return $r;
    }

    /**
     * 创建兜底结果
     */
    public static function fallback(string $fallbackSource, string $action, $data, string $originalSource, string $originalError, array $meta = []): self
    {
        $r = new self();
        $r->success      = true;
        $r->source       = $fallbackSource;
        $r->action       = $action;
        $r->status       = self::STATUS_FALLBACK_USED;
        $r->data         = $data;
        $r->meta         = array_merge([
            'provider_status' => 200,
            'cache'           => 'miss',
            'updated_at'      => date('c'),
            'fallback_trace'  => [
                ['source' => $originalSource, 'error' => $originalError],
            ],
        ], $meta);
        return $r;
    }

    /**
     * 是否有可用数据（含兜底）
     */
    public function hasData(): bool
    {
        if (!$this->success || $this->data === null) {
            return false;
        }
        return !is_array($this->data) || count($this->data) > 0;
    }

    /**
     * 是否为兜底数据
     */
    public function isFallback(): bool
    {
        return $this->status === self::STATUS_FALLBACK_USED;
    }

    /**
     * 转为 API 响应数组
     */
    public function toResponse(bool $raw = false): array
    {
        if ($this->success) {
            $resp = [
                'success' => true,
                'source'  => $this->source,
                'action'  => $this->action,
                'data'    => $this->data,
                'meta'    => $this->meta,
            ];
            if ($this->isFallback()) {
                $resp['fallback'] = true;
            }
            return $resp;
        }

        return [
            'success'           => false,
            'source'            => $this->source,
            'action'            => $this->action,
            'code'              => $this->errorCode,
            'message'           => $this->errorMessage,
            'fallback_available' => true,
            'meta'              => $this->meta,
        ];
    }

    // ── Phase 1: 统一 envelope 契约 ──

    /**
     * 规范化 meta.data_status
     *
     * severity:     ok | info | warning | error
     * freshness:    fresh | cached | stale | unknown
     * completeness: complete | partial | empty | unknown
     * route:        primary | fallback | failed
     */
    public function computeDataStatus(): array
    {
        $warnings = [];
        $cacheState = (string)($this->meta['cache'] ?? 'miss');

        // freshness：由缓存状态推导
        if (in_array($cacheState, ['stale', 'stale_fallback'], true)) {
            $freshness = 'stale';
            $warnings[] = ['code' => 'stale_cache', 'message' => '数据来自过期缓存降级'];
        } elseif (in_array($cacheState, ['hit', 'hit_after_wait'], true)) {
            $freshness = 'cached';
        } elseif (in_array($cacheState, ['miss', 'miss_after_wait'], true)) {
            $freshness = $this->success ? 'fresh' : 'unknown';
        } else {
            $freshness = 'unknown';
        }

        // route
        if (!$this->success || !$this->hasData()) {
            $route = 'failed';
        } elseif ($this->isFallback()) {
            $route = 'fallback';
            $warnings[] = ['code' => 'fallback_source', 'message' => '主数据源失败，使用备用数据源'];
        } else {
            $route = 'primary';
        }

        // counts / completeness
        $counts = null;
        if (isset($this->meta['counts']) && is_array($this->meta['counts'])) {
            $counts = [
                'expected' => (int)($this->meta['counts']['expected'] ?? 0),
                'returned' => (int)($this->meta['counts']['returned'] ?? 0),
                'missing'  => array_values((array)($this->meta['counts']['missing'] ?? [])),
            ];
        } elseif (is_array($this->data)) {
            $n = count($this->data);
            $counts = ['expected' => $n, 'returned' => $n, 'missing' => []];
        }

        if (!$this->success || $this->data === null) {
            $completeness = $this->success ? 'empty' : 'unknown';
        } elseif (is_array($this->data) && count($this->data) === 0) {
            $completeness = 'empty';
        } elseif ($counts !== null && ($counts['returned'] < $counts['expected'] || !empty($counts['missing']))) {
            $completeness = 'partial';
            $warnings[] = ['code' => 'partial_data', 'message' => '部分请求项未返回数据'];
        } elseif (!empty($this->meta['partial'])) {
            $completeness = 'partial';
            $warnings[] = ['code' => 'partial_data', 'message' => '数据覆盖不完整'];
        } else {
            $completeness = 'complete';
        }

        // 数据内容时效与缓存时效是两回事：刚请求到的上一交易日净值不能标成盘中实时。
        $dataRecency = (string)($this->meta['data_recency'] ?? 'unknown');
        $nonRealtimeCount = max(0, (int)($this->meta['non_realtime_count'] ?? 0));
        $nonRealtimeLabel = trim((string)($this->meta['non_realtime_label'] ?? '带日期数据')) ?: '带日期数据';
        if ($nonRealtimeCount > 0 || in_array($dataRecency, ['dated', 'mixed'], true)) {
            $warnings[] = [
                'code' => 'non_realtime_data',
                'message' => $nonRealtimeCount > 0
                    ? "{$nonRealtimeCount} 项为{$nonRealtimeLabel}，不是盘中实时值"
                    : '响应包含非盘中实时数据',
            ];
        }

        // severity：error > warning > info > ok
        if ($route === 'failed') {
            $severity = 'error';
        } elseif ($freshness === 'stale' || $completeness === 'partial' || $nonRealtimeCount > 0 || in_array($dataRecency, ['dated', 'mixed'], true)) {
            $severity = 'warning';
        } elseif ($route === 'fallback' || $freshness === 'cached') {
            $severity = 'info';
        } else {
            $severity = 'ok';
        }

        $status = [
            'severity'     => $severity,
            'freshness'    => $freshness,
            'completeness' => $completeness,
            'route'        => $route,
            'data_recency' => $dataRecency,
            'non_realtime_count' => $nonRealtimeCount,
            'warnings'     => $warnings,
        ];
        if ($counts !== null) {
            $status['counts'] = $counts;
        }
        return $status;
    }

    /**
     * 转为统一 envelope 响应（format=envelope）
     *
     * 兼容字段（success/data/message/total 等）保留；新增 meta.data_status、
     * request_id、observed_at、data_at、cache_age_seconds。
     */
    public function toEnvelope(array $extra = []): array
    {
        $meta = $this->meta;
        $meta['request_id']  = $meta['request_id'] ?? substr(bin2hex(random_bytes(6)), 0, 8);
        $meta['observed_at'] = date('c');
        // 未取得真实上游数据时间时必须为 null，不得用服务器时间冒充行情时间。
        $meta['data_at'] = $meta['data_at'] ?? null;
        if (!isset($meta['cache_age_seconds'])) {
            $meta['cache_age_seconds'] = in_array((string)($meta['cache'] ?? ''), ['miss', 'miss_after_wait'], true) ? 0 : null;
        }
        $meta['fallback_trace'] = $meta['fallback_trace'] ?? [];
        $meta['data_status'] = $this->computeDataStatus();

        if ($this->success) {
            $resp = [
                'success' => true,
                'source'  => $this->source,
                'action'  => $this->action,
                'status'  => $this->status,
                'data'    => $this->data,
                'meta'    => $meta,
            ];
            if (is_array($this->data)) {
                $resp['total'] = count($this->data);
            }
            if ($this->isFallback()) {
                $resp['fallback'] = true;
            }
            return array_merge($resp, $extra);
        }

        return array_merge([
            'success' => false,
            'source'  => $this->source,
            'action'  => $this->action,
            'status'  => $this->status,
            'code'    => $this->errorCode,
            'message' => $this->errorMessage,
            'data'    => null,
            'meta'    => $meta,
        ], $extra);
    }

    /**
     * 声明批量请求的期望/返回/缺失计数（写入 meta.counts）
     *
     * @param string[] $expectedCodes 请求的代码列表
     * @param string[] $returnedCodes 实际返回的代码列表
     */
    public function setBatchCounts(array $expectedCodes, array $returnedCodes): void
    {
        $normalize = static function (string $c): string {
            return strtolower(preg_replace('/^(sh|sz|bj)/i', '', trim($c)));
        };
        $expectedNorm = array_map($normalize, $expectedCodes);
        $returnedNorm = array_map($normalize, $returnedCodes);
        $missing = [];
        foreach ($expectedCodes as $i => $orig) {
            if (!in_array($expectedNorm[$i], $returnedNorm, true)) {
                $missing[] = $orig;
            }
        }
        $this->meta['counts'] = [
            'expected' => count($expectedCodes),
            'returned' => count($returnedCodes),
            'missing'  => $missing,
        ];
    }

    /**
     * 转为 JSON 字符串
     */
    public function toJson(bool $raw = false): string
    {
        return json_encode($this->toResponse($raw), JSON_UNESCAPED_UNICODE);
    }
}
