<?php
/**
 * SecurityAudit — 统一安全审查类
 * 覆盖所有输入接口的参数验证、清洗、速率限制和安全响应头
 *
 * Phase 2: 限流支持 Redis backend，维度扩展为 IP/endpoint/全局
 *
 * 使用方式：
 *   require_once __DIR__ . '/SecurityAudit.php';
 *   SecurityAudit::init();                          // 设置安全头 + CORS + 速率限制
 *   $code = SecurityAudit::getParam('code', '', [   // 获取并验证 GET 参数
 *       'required' => true,
 *       'pattern'  => SecurityAudit::STOCK_CODE_PATTERN,
 *   ]);
 */

require_once __DIR__ . '/lib/CacheStoreFactory.php';

class SecurityAudit
{
    // ============================================================
    // 运行时模式
    // ============================================================

    /** @var bool 是否以 SSE 模式拒绝请求（SSE 接口设为 true） */
    private static $sseMode = false;

    // ============================================================
    // 常量：正则 / 白名单 / 限制
    // ============================================================

    /** 股票代码：字母、数字、点号 */
    const STOCK_CODE_PATTERN = '/^[A-Za-z0-9.]+$/';

    /** 基金代码：6 位纯数字 */
    const FUND_CODE_PATTERN = '/^\d{6}$/';

    /** 日期：YYYY-MM-DD */
    const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /** K 线频率白名单 */
    const ALLOWED_FREQUENCIES = ['1m', '5m', '15m', '30m', '60m', '1d', '1w', '1M'];

    /** 热门股票排序字段白名单 */
    const ALLOWED_SORT_FIELDS = ['f62', 'f184', 'f66', 'f72', 'f6', 'f3'];

    /** 板块资金排序字段白名单 */
    const ALLOWED_SECTOR_KEYS = ['f62', 'f164', 'f174'];

    /** 板块类型白名单 */
    const ALLOWED_SECTOR_TYPES = ['industry', 'concept', 'theme', 'region'];

    /** 数据源白名单 */
    const ALLOWED_DATA_SOURCES = ['auto', 'eastmoney', 'ashare', 'xueqiu'];

    /** 雪球 action 白名单 */
    const ALLOWED_XUEQIU_ACTIONS = ['quote', 'kline', 'hot_stock', 'screener', 'fundx'];

    /** 雪球 K 线 period 白名单 */
    const ALLOWED_XUEQIU_PERIODS = ['1m', '5m', '15m', '30m', '60m', 'day', 'week', 'month'];

    /** 雪球热度榜 type 白名单 */
    const ALLOWED_XUEQIU_HOT_TYPES = ['10', '11', '12', '13', '14'];

    /** 条件选股排序字段白名单 */
    const ALLOWED_SCREENER_ORDER_FIELDS = ['percent', 'amount', 'volume', 'turnover_rate', 'volume_ratio', 'market_capital', 'float_market_capital', 'pe_ttm', 'pb', 'roe_ttm', 'dividend_yield', 'followers', 'limitup_days'];

    /** 条件选股市场白名单 */
    const ALLOWED_SCREENER_MARKETS = ['CN', 'HK', 'US'];

    /** 条件选股类型白名单 */
    const ALLOWED_SCREENER_TYPES = ['11', '82', '30', '', 'ashare', 'hk', 'us', 'sh_sz', 'sh', 'sz', 'bj', 'kcb', 'cyb'];

    /** 搜索关键词最大长度 */
    const MAX_KEYWORD_LENGTH = 100;

    /** AI 单条消息最大长度 */
    const MAX_MESSAGE_LENGTH = 50000;

    /** AI 会话最大消息条数 */
    const MAX_MESSAGE_COUNT = 100;

    /** 批量代码查询最大数量 */
    const MAX_CODES_COUNT = 20;

    /** 单个代码最大长度 */
    const MAX_CODE_LENGTH = 20;

    /** JSON 请求体最大字节数 (4 MB)，用于支持较长的多轮 AI 上下文 */
    const MAX_JSON_BODY_SIZE = 4194304;

    /** 默认速率限制：窗口时间内最大请求数 */
    const DEFAULT_RATE_LIMIT = 60;

    /** 默认速率限制：窗口时间（秒） */
    const DEFAULT_RATE_WINDOW = 60;

    /** 全局速率限制：窗口时间内最大请求数 */
    const GLOBAL_RATE_LIMIT = 500;

    /** 全局速率限制：窗口时间（秒） */
    const GLOBAL_RATE_WINDOW = 60;

    /** 是否信任反向代理传递的 IP 头（仅在确认部署了可信代理时设为 true） */
    const TRUST_PROXY = false;

    /** @var string|null 全局限流 key 前缀 */
    private static $globalRatePrefix = 'fa:rl:global';

    // ============================================================
    // 初始化
    // ============================================================

    /**
     * 快速初始化：安全头 + CORS 校验 + 速率限制
     *
     * @param array $opts [
     *   'cors'        => true,          // 是否执行同源 CORS 校验
     *   'rate_limit'  => 60,            // 0 = 不限速
     *   'rate_window' => 60,
     *   'endpoint'    => 'default',     // 速率限制键名
     *   'global_limit'  => 500,         // 全局限流（0 = 不限）
     *   'global_window' => 60,
     * ]
     */
    public static function init(array $opts = [])
    {
        $cors          = $opts['cors']          ?? true;
        $rateLimit     = $opts['rate_limit']    ?? self::DEFAULT_RATE_LIMIT;
        $rateWindow    = $opts['rate_window']   ?? self::DEFAULT_RATE_WINDOW;
        $endpoint      = $opts['endpoint']      ?? 'default';
        $globalLimit   = $opts['global_limit']  ?? self::GLOBAL_RATE_LIMIT;
        $globalWindow  = $opts['global_window'] ?? self::GLOBAL_RATE_WINDOW;

        // 设置 SSE 模式标记
        self::$sseMode = !empty($opts['sse']);

        self::setSecurityHeaders();

        if ($cors) {
            self::validateOrigin();
        }

        // 全局限流优先检查
        if ($globalLimit > 0) {
            self::checkGlobalRateLimit($globalLimit, $globalWindow);
        }

        // IP + endpoint 限流
        if ($rateLimit > 0) {
            self::checkRateLimit($endpoint, $rateLimit, $rateWindow);
        }
    }

    // ============================================================
    // 安全响应头
    // ============================================================

    /**
     * 设置通用安全响应头
     */
    public static function setSecurityHeaders()
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header_remove('X-Powered-By');
    }

    // ============================================================
    // CORS 同源校验
    // ============================================================

    /**
     * 校验 Origin 是否同源；非同源直接 403
     *
     * @param array|null $allowedOrigins 允许的来源列表；null = 仅同源
     */
    public static function validateOrigin(?array $allowedOrigins = null)
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $host   = $_SERVER['HTTP_HOST'] ?? '';

        if (!$origin || !$host) {
            return; // 非浏览器 / 无 Origin 头，放行
        }

        if ($allowedOrigins === null) {
            // 仅允许同源
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $self   = $scheme . '://' . $host;
            if ($origin !== $self) {
                self::reject('跨域请求不允许', 403);
            }
        } else {
            if (!in_array($origin, $allowedOrigins, true)) {
                self::reject('跨域请求不允许', 403);
            }
        }
    }

    // ============================================================
    // 参数获取与验证
    // ============================================================

    /**
     * 获取并验证 GET 参数
     *
     * @param string $name    参数名
     * @param mixed  $default 默认值
     * @param array  $rules   验证规则
     * @return mixed
     */
    public static function getParam(string $name, $default = '', array $rules = [])
    {
        $value = isset($_GET[$name]) ? $_GET[$name] : $default;
        return self::applyRules($value, $rules, $name);
    }

    /**
     * 获取并验证 POST 参数
     *
     * @param string $name    参数名
     * @param mixed  $default 默认值
     * @param array  $rules   验证规则
     * @return mixed
     */
    public static function postParam(string $name, $default = '', array $rules = [])
    {
        $value = isset($_POST[$name]) ? $_POST[$name] : $default;
        return self::applyRules($value, $rules, $name);
    }

    /**
     * 获取并验证 JSON 请求体
     *
     * @param array $fieldRules 字段级验证规则 ['field' => [rules...]]
     * @return array
     */
    public static function getJsonBody(array $fieldRules = []): array
    {
        $raw = file_get_contents('php://input');

        if (strlen($raw) > self::MAX_JSON_BODY_SIZE) {
            self::reject('请求体过大，最大允许 4 MB');
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::reject('JSON 格式不正确: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            self::reject('请求体必须是 JSON 对象');
        }

        foreach ($fieldRules as $field => $rules) {
            if (isset($data[$field])) {
                $data[$field] = self::applyRules($data[$field], $rules, $field);
            }
        }

        return $data;
    }

    // ============================================================
    // 专用验证方法
    // ============================================================

    /**
     * 验证股票/基金代码列表（逗号分隔）
     *
     * @param string $codes     原始逗号分隔字符串
     * @param string $pattern   单个代码的正则
     * @param int    $maxCount  最大数量
     * @return array 有效代码数组
     */
    public static function validateCodeList(string $codes, string $pattern = self::STOCK_CODE_PATTERN, int $maxCount = self::MAX_CODES_COUNT): array
    {
        $list = array_map('trim', explode(',', $codes));
        $valid = [];

        foreach ($list as $c) {
            $c = self::sanitize($c, 'stock_code');
            if ($c === '') {
                continue;
            }
            if (strlen($c) > self::MAX_CODE_LENGTH) {
                self::reject("代码 {$c} 长度超过限制（最大 " . self::MAX_CODE_LENGTH . " 字符）");
            }
            if (!preg_match($pattern, $c)) {
                self::reject("代码 {$c} 格式不正确");
            }
            $valid[] = $c;
        }

        if (empty($valid)) {
            self::reject('没有有效的代码');
        }

        if (count($valid) > $maxCount) {
            self::reject("代码数量超过限制，最多 {$maxCount} 个");
        }

        return $valid;
    }

    /**
     * 验证 AI 聊天消息数组
     *
     * @param array $messages
     * @return array
     */
    public static function validateMessages(array $messages): array
    {
        if (count($messages) > self::MAX_MESSAGE_COUNT) {
            self::reject('消息数量超过限制，最多 ' . self::MAX_MESSAGE_COUNT . ' 条');
        }

        $allowedRoles = ['system', 'user', 'assistant'];

        foreach ($messages as $i => $msg) {
            if (!is_array($msg)) {
                self::reject("第 {$i} 条消息格式不正确");
            }

            if (!isset($msg['role']) || !in_array($msg['role'], $allowedRoles, true)) {
                self::reject("第 {$i} 条消息角色无效");
            }

            if (!isset($msg['content']) || !is_string($msg['content'])) {
                self::reject("第 {$i} 条消息内容无效");
            }

            if (mb_strlen($msg['content']) > self::MAX_MESSAGE_LENGTH) {
                self::reject("第 {$i} 条消息内容过长，最大 " . self::MAX_MESSAGE_LENGTH . " 字符");
            }
        }

        return $messages;
    }

    /**
     * 验证 HTTP 请求方法
     *
     * @param string $method 允许的方法（如 'GET', 'POST'）
     */
    public static function requireMethod(string $method): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== $method) {
            self::reject('请求方法不允许，仅支持 ' . $method, 405);
        }
    }

    // ============================================================
    // 清洗方法
    // ============================================================

    /**
     * 按类型清洗字符串
     *
     * @param string $value
     * @param string $type  alphanum_dot | alphanum | digits | text | stock_code
     * @return string
     */
    public static function sanitize(string $value, string $type = 'alphanum_dot'): string
    {
        switch ($type) {
            case 'alphanum_dot':
                return preg_replace('/[^A-Za-z0-9.]/', '', $value);
            case 'alphanum':
                return preg_replace('/[^A-Za-z0-9]/', '', $value);
            case 'digits':
                return preg_replace('/[^0-9]/', '', $value);
            case 'text':
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            case 'keyword':
                return preg_replace('/[\x00-\x1F\x7F]/', '', trim($value));
            case 'stock_code':
                return preg_replace('/[^A-Za-z0-9.]/', '', $value);
            default:
                return preg_replace('/[^A-Za-z0-9.]/', '', $value);
        }
    }

    // ============================================================
    // 速率限制（Phase 2: Redis 优先 + 文件 fallback + 全局限流）
    // ============================================================

    /**
     * 检查速率限制（IP + endpoint 维度）
     *
     * @param string $key          限制键（如接口名）
     * @param int    $maxRequests  窗口内最大请求数
     * @param int    $windowSeconds 窗口秒数
     */
    public static function checkRateLimit(string $key = 'default', int $maxRequests = self::DEFAULT_RATE_LIMIT, int $windowSeconds = self::DEFAULT_RATE_WINDOW): void
    {
        $ip  = self::getClientIP();
        $rlKey = "ip:{$ip}:{$key}";
        $now  = time();

        // 尝试 Redis 限流
        $store = CacheStoreFactory::getInstance();
        if ($store instanceof RedisCacheStore) {
            $redis = $store->redis();
            if ($redis !== null) {
                self::checkRateLimitRedis($redis, $rlKey, $maxRequests, $windowSeconds, $now);
                return;
            }
        }

        // Fallback: 文件限流
        self::checkRateLimitFile($ip, $key, $maxRequests, $windowSeconds, $now);
    }

    /**
     * 全局速率限制（不分 IP，全站总并发）
     */
    public static function checkGlobalRateLimit(int $maxRequests = self::GLOBAL_RATE_LIMIT, int $windowSeconds = self::GLOBAL_RATE_WINDOW): void
    {
        $now = time();
        $gKey = 'global';

        $store = CacheStoreFactory::getInstance();
        if ($store instanceof RedisCacheStore) {
            $redis = $store->redis();
            if ($redis !== null) {
                self::checkRateLimitRedis($redis, $gKey, $maxRequests, $windowSeconds, $now);
                return;
            }
        }

        self::checkRateLimitFile('__global__', $gKey, $maxRequests, $windowSeconds, $now);
    }

    /**
     * Redis 限流实现（滑动窗口计数器）
     */
    private static function checkRateLimitRedis(\Redis $redis, string $key, int $maxRequests, int $windowSeconds, int $now): void
    {
        try {
            $redisKey = self::$globalRatePrefix . ':' . $key;
            $allowed = false;
            $remaining = 0;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $redis->zRemRangeByScore($redisKey, '-inf', $now - $windowSeconds);
                $redis->watch($redisKey);
                $count = (int)$redis->zCard($redisKey);
                $remaining = max(0, $maxRequests - $count);

                if ($count >= $maxRequests) {
                    $redis->unwatch();
                    break;
                }

                $member = $now . ':' . uniqid('', true);
                $redis->multi();
                $redis->zAdd($redisKey, $now, $member);
                $redis->expire($redisKey, $windowSeconds + 10);
                $exec = $redis->exec();
                if ($exec !== false) {
                    $allowed = true;
                    $remaining = max(0, $maxRequests - $count - 1);
                    break;
                }
            }

            header('X-RateLimit-Limit: ' . $maxRequests);
            header('X-RateLimit-Remaining: ' . $remaining);

            if (!$allowed) {
                self::rejectRateLimit($maxRequests, $windowSeconds);
            }
        } catch (\Exception $e) {
            // Redis 故障放行但记录日志
            error_log("[SecurityAudit] Redis 限流异常: " . $e->getMessage());
        }
    }

    /**
     * 文件限流实现（原有逻辑，作为 fallback）
     */
    private static function checkRateLimitFile(string $ip, string $key, int $maxRequests, int $windowSeconds, int $now): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_rl';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true)) {
                error_log("[SecurityAudit] 限流目录创建失败: {$dir}");
                return;
            }
        }

        $file = $dir . DIRECTORY_SEPARATOR . md5($ip . '_' . $key) . '.json';

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            error_log("[SecurityAudit] 限流文件打开失败: {$file}");
            return;
        }

        if (!flock($fp, LOCK_EX)) {
            error_log("[SecurityAudit] 限流文件锁获取失败: {$file}");
            fclose($fp);
            return;
        }

        $size = filesize($file);
        $data = $size > 0 ? json_decode(fread($fp, $size), true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $data = array_filter($data, function ($ts) use ($now, $windowSeconds) {
            return ($now - $ts) < $windowSeconds;
        });

        $remaining = max(0, $maxRequests - count($data));
        header('X-RateLimit-Limit: ' . $maxRequests);
        header('X-RateLimit-Remaining: ' . $remaining);

        if (count($data) >= $maxRequests) {
            flock($fp, LOCK_UN);
            fclose($fp);
            self::rejectRateLimit($maxRequests, $windowSeconds);
        }

        $data[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 获取客户端 IP（代理感知）
     */
    private static function getClientIP(): string
    {
        if (self::TRUST_PROXY) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $ip  = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = trim($_SERVER['HTTP_X_REAL_IP']);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    // ============================================================
    // 内部规则引擎
    // ============================================================

    /**
     * 对值逐一应用规则
     *
     * @param mixed  $value
     * @param array  $rules
     * @param string $fieldName
     * @return mixed
     */
    private static function applyRules($value, array $rules, string $fieldName)
    {
        $isEmpty = ($value === '' || $value === null);
        $isRequired = !empty($rules['required']);

        foreach ($rules as $rule => $param) {
            switch ($rule) {
                case 'required':
                    if ($param && $isEmpty) {
                        self::reject("参数 {$fieldName} 不能为空");
                    }
                    break;

                case 'pattern':
                    if ($isEmpty && !$isRequired) {
                        break;
                    }
                    if (!preg_match($param, (string)$value)) {
                        self::reject("参数 {$fieldName} 格式不正确");
                    }
                    break;

                case 'whitelist':
                    if ($isEmpty && !$isRequired) {
                        break;
                    }
                    if (!in_array($value, $param, true)) {
                        self::reject("参数 {$fieldName} 值无效");
                    }
                    break;

                case 'int':
                    $value = intval($value);
                    break;

                case 'min':
                    if ($isEmpty && !$isRequired) {
                        break;
                    }
                    if (intval($value) < $param) {
                        self::reject("参数 {$fieldName} 值过小，最小为 {$param}");
                    }
                    break;

                case 'max':
                    if ($isEmpty && !$isRequired) {
                        break;
                    }
                    if (intval($value) > $param) {
                        self::reject("参数 {$fieldName} 值过大，最大为 {$param}");
                    }
                    break;

                case 'maxLength':
                    if ($isEmpty && !$isRequired) {
                        break;
                    }
                    if (mb_strlen((string)$value) > $param) {
                        self::reject("参数 {$fieldName} 过长，最大 {$param} 字符");
                    }
                    break;

                case 'minLength':
                    if ($isEmpty && !$isRequired) {
                        break;
                    }
                    if (mb_strlen((string)$value) < $param) {
                        self::reject("参数 {$fieldName} 过短，最小 {$param} 字符");
                    }
                    break;

                case 'sanitize':
                    if (!$isEmpty) {
                        $value = self::sanitize((string)$value, $param);
                    }
                    break;
            }
        }
        return $value;
    }

    // ============================================================
    // 拒绝请求
    // ============================================================

    /**
     * 以 JSON 格式拒绝请求
     *
     * @param string $message   错误消息
     * @param int    $httpCode  HTTP 状态码
     */
    public static function reject(string $message, int $httpCode = 400): void
    {
        if (self::$sseMode) {
            self::rejectSSE($message, $httpCode);
            return;
        }
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 以 SSE 事件格式拒绝请求（用于 AI 流式接口）
     *
     * @param string $message   错误消息
     * @param int    $httpCode  HTTP 状态码
     */
    public static function rejectSSE(string $message, int $httpCode = 400): void
    {
        http_response_code($httpCode);
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache');
        echo "data: " . json_encode(['error' => ['message' => $message, 'type' => 'security']], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
        exit;
    }

    /**
     * 以 429 拒绝速率超限请求
     */
    private static function rejectRateLimit(int $maxRequests, int $windowSeconds): void
    {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);

        $message = "请求过于频繁，请 {$windowSeconds} 秒后重试";

        if (self::$sseMode) {
            header('Content-Type: text/event-stream; charset=UTF-8');
            header('Cache-Control: no-cache');
            echo "data: " . json_encode([
                'error' => [
                    'message'     => $message,
                    'type'        => 'rate_limit',
                    'retry_after' => $windowSeconds,
                ],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'     => false,
            'message'     => $message,
            'retry_after' => $windowSeconds,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
