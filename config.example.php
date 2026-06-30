<?php
/**
 * FuckAshare 配置示例
 *
 * 复制此文件为 config.php 并根据实际环境修改。
 * config.php 不提交到版本库（已在 .gitignore 中）。
 */

return [

    // ── Redis 配置 ──
    // Redis 用于缓存、限流、熔断、分布式锁、请求合并状态
    // 不配置或留空则自动降级到文件缓存
    'redis' => [
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'password' => '',          // 无密码留空
        'db'       => 0,
        'prefix'   => 'fa:',       // key 前缀，多实例部署时可区分
        'timeout'  => 2.0,         // 连接超时（秒）
    ],

    // ── 限流配置 ──
    'rate_limit' => [
        // IP + endpoint 限流
        'default_limit'  => 60,     // 每窗口最大请求数
        'default_window' => 60,     // 窗口时间（秒）

        // 全局限流（不分 IP）
        'global_limit'  => 500,     // 每窗口全站最大请求数
        'global_window' => 60,

        // AI SSE 限流
        'ai_limit'  => 10,
        'ai_window' => 60,
    ],

    // ── 熔断器配置 ──
    // 各数据源独立熔断阈值和冷却时间
    // 未配置时使用 CircuitBreaker::SOURCE_DEFAULTS 中的默认值
    'circuit_breaker' => [
        'xueqiu'    => ['failure_threshold' => 3,  'cooldown' => 60],
        'eastmoney' => ['failure_threshold' => 5,  'cooldown' => 30],
        'ashare'    => ['failure_threshold' => 3,  'cooldown' => 60],
        'fund'      => ['failure_threshold' => 5,  'cooldown' => 30],
    ],

    // ── 缓存 TTL 配置（秒）──
    // 覆盖 MarketDataService 和 FundService 中的默认值
    'cache_ttl' => [
        // MarketDataService
        'quote'         => 10,      // 行情实时报价
        'kline_min'     => 60,      // 分钟 K 线
        'kline_day'     => 300,     // 日线/周线/月线 K 线
        'hot_stock'     => 60,      // 雪球热度榜
        'screener'      => 120,     // 条件选股
        'fundx'         => 180,     // 动态资讯
        'stock_flow'    => 30,      // 个股资金流向
        'sector_flow'   => 60,      // 板块资金流向
        'hot_stocks'    => 30,      // 热门股票资金榜
        // FundService
        'estimate'      => 10,      // 基金实时估值
        'batch_estimate' => 10,     // 批量基金估值
        'info'          => 300,     // 基金详情
        'search'        => 600,     // 基金搜索
    ],

    // ── 缓存降级配置 ──
    'cache_degradation' => [
        'negative_cache_ttl'   => 10,   // 上游失败缓存时间
        'stale_window'         => 600,  // stale 缓存最大有效窗口
        'stampede_lock_ttl'    => 5,    // 防击穿锁超时
        'stampede_wait_ms'     => 500,  // 防击穿等待时间
    ],

    // ── AI 配置 ──
    'ai' => [
        // 默认渠道: deepseek | openai
        'default_channel' => 'deepseek',

        // 并发流限制
        'max_concurrent_per_ip' => 2,       // 每 IP 最多同时 AI 流数
        'max_concurrent_global' => 10,      // 全局最多同时 AI 流数

        // 超时配置（秒）
        'timeout'          => 300,          // curl 请求超时（推理模型思考时间可能很长）
        'connect_timeout'  => 15,           // curl 连接超时

        // 并发锁过期阈值（秒）
        // 大于 timeout 即可，用于清理僵尸锁文件
        'stale_threshold'  => 310,

        // SSE 心跳间隔（秒）
        // 长连接期间定时发送 keepalive 事件，防止中间代理/CDN 断开连接
        // 设为 0 则禁用心跳
        'heartbeat_interval' => 15,

        // 消息内容限制（覆盖 SecurityAudit 中的默认值）
        'max_message_length' => 50000,      // 单条消息最大字符数
        'max_message_count'  => 100,        // 会话最大消息条数

        // ── 渠道定义 ──
        // api_key 必须在 config.php 中填写，切勿提交到版本库
        'channels' => [
            'deepseek' => [
                'name'    => 'DeepSeek',
                'api_url' => 'https://api.deepseek.com/chat/completions',
                'api_key' => '',               // ← 必填
                'model'   => 'deepseek-chat',
            ],
            'openai' => [
                'name'    => 'OpenAI兼容',
                'api_url' => '',               // ← 必填，如 https://api.example.com/v1/chat/completions
                'api_key' => '',               // ← 必填
                'model'   => '',               // ← 必填，如 gpt-4o、kimi-k2.6
            ],
        ],
    ],

    // ── Python 数据服务 ──
    // Phase 3: Python 长驻服务地址（当前仍使用 exec()）
    'python_service' => [
        'enabled' => false,
        'url'     => 'http://127.0.0.1:8900',
    ],

    // ── Python CLI ──
    // AshareBridge 执行 get_stock_data.py 时使用；留空则自动探测可导入 pandas/requests 的 Python
    'python' => [
        'binary' => '',
    ],

    // ── 安全配置 ──
    'security' => [
        // 仅在确认部署了可信反向代理时设为 true
        // 启用后会从 X-Forwarded-For / X-Real-IP 头读取客户端 IP
        'trust_proxy' => false,

        // 限流配置覆盖（可选，留空则使用 SecurityAudit 内置常量）
        // 'rate_limit' => [
        //     'default_limit'  => 60,
        //     'default_window' => 60,
        //     'global_limit'   => 500,
        //     'global_window'  => 60,
        // ],
    ],
];
