<?php
/**
 * HttpClient — 统一 cURL 请求封装
 * 超时、请求头、JSON 解析、错误归类
 */

class HttpClient
{
    const DEFAULT_TIMEOUT = 10;
    const DEFAULT_CONNECT_TIMEOUT = 5;

    /** @var int 最后一次请求的 HTTP 状态码 */
    public $lastHttpCode = 0;

    /** @var float 最后一次请求耗时（秒） */
    public $lastDuration = 0.0;

    /** @var string 最后一次请求的 Content-Type */
    public $lastContentType = '';

    /** @var string|null cookie jar 文件路径 */
    private $cookieJar = null;

    /** @var array 默认请求头 */
    private $defaultHeaders = [];

    /** @var int 超时秒数 */
    private $timeout;

    /** @var int 连接超时秒数 */
    private $connectTimeout;

    /**
     * @param array $opts [
     *   'timeout'         => 10,
     *   'connect_timeout' => 5,
     *   'headers'         => [],
     *   'cookie_jar'      => null,  // 临时文件路径
     * ]
     */
    public function __construct(array $opts = [])
    {
        $this->timeout        = $opts['timeout'] ?? self::DEFAULT_TIMEOUT;
        $this->connectTimeout = $opts['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT;
        $this->defaultHeaders = $opts['headers'] ?? [];
        $this->cookieJar      = $opts['cookie_jar'] ?? null;
    }

    /**
     * GET 请求
     *
     * @param string $url
     * @param array  $headers  额外请求头
     * @return array ['body' => string, 'http_code' => int, 'error' => string|null, 'content_type' => string]
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * POST 请求
     *
     * @param string $url
     * @param mixed  $data
     * @param array  $headers
     * @return array
     */
    public function post(string $url, $data = null, array $headers = []): array
    {
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * 通用请求
     *
     * @param string      $method
     * @param string      $url
     * @param mixed       $data
     * @param array       $headers
     * @return array ['body' => string, 'http_code' => int, 'error' => string|null, 'content_type' => string]
     */
    private function request(string $method, string $url, $data = null, array $headers = []): array
    {
        $start = microtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_ENCODING, '');

        // 合并请求头（转为 "Key: Value" 格式）
        $allHeaders = array_merge($this->defaultHeaders, $headers);
        $headerLines = [];
        foreach ($allHeaders as $k => $v) {
            if (is_int($k)) {
                // 已经是 "Key: Value" 格式
                $headerLines[] = $v;
            } else {
                $headerLines[] = "{$k}: {$v}";
            }
        }
        if (!empty($headerLines)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        // Cookie jar
        if ($this->cookieJar) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJar);
        }

        // POST 数据
        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($data) ? $data : json_encode($data));
        }

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        $error    = curl_error($ch);
        curl_close($ch);

        $this->lastHttpCode    = $httpCode;
        $this->lastDuration    = microtime(true) - $start;
        $this->lastContentType = $contentType;

        return [
            'body'         => $body ?: '',
            'http_code'    => $httpCode,
            'error'        => $error ?: null,
            'content_type' => $contentType,
        ];
    }

    /**
     * 解析 JSON 响应
     *
     * @param string $body
     * @return array ['ok' => bool, 'data' => mixed, 'error' => string|null]
     */
    public static function parseJson(string $body): array
    {
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['ok' => false, 'data' => null, 'error' => 'JSON 解析失败: ' . json_last_error_msg()];
        }
        return ['ok' => true, 'data' => $data, 'error' => null];
    }

    /**
     * 创建临时 cookie jar 文件
     *
     * @return string 文件路径
     */
    public static function createTempCookieJar(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_cookies';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return tempnam($dir, 'xq_');
    }

    /**
     * 删除 cookie jar 文件
     */
    public function cleanup(): void
    {
        if ($this->cookieJar && file_exists($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }
}
