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
        return $this->success && $this->data !== null;
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

    /**
     * 转为 JSON 字符串
     */
    public function toJson(bool $raw = false): string
    {
        return json_encode($this->toResponse($raw), JSON_UNESCAPED_UNICODE);
    }
}
