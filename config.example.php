<?php
/**
 * FuckAshare 配置示例文件（模板）
 *
 * 用途：本项目统一配置模板。请复制此文件为 config.php 并按实际环境修改。
 *       config.php 为本地真实配置（含密钥），已在 .gitignore 中排除，切勿提交到版本库。
 *
 * ┌─ 注释规范（请在 config.php 中保持一致）──────────────────────────────────┐
 * │ 本文件对每个配置项采用「行内中文注释」，尽量包含以下要素（如适用）：        │
 * │   · 用途      —— 该配置项的作用与影响范围                                 │
 * │   · 单位      —— 计量单位（秒 / 毫秒 / 字节 / 次等）                      │
 * │   · 默认值    —— 未配置时代码中的 fallback 值，并标注来源（类常量/文件）   │
 * │   · 读取位置  —— 实际消费该配置的文件 / 类 / 方法                         │
 * │   · 生效状态  —— ✅已生效 / ⚠️预留未接线 / ❌未生效（死配置）             │
 * │ 修改 config.php 时请沿用此规范，便于团队协作与后续维护。                   │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * 配置加载机制：
 *   - lib/AppConfig.php 负责加载根目录 config.php，返回一个关联数组。
 *   - 读取方式为「点路径」，例如 AppConfig::get('redis.host')、AppConfig::get('ai.channels.deepseek.api_key')。
 *   - 任意缺失的键都会自动回退到各服务类内置常量，因此本文件可按需只覆盖部分项。
 *
 * 重要提示：
 *   标注 ⚠️ 或 ❌ 的项为「预留」或「尚未接线」的配置，当前修改后不会生效，
 *   仅供未来版本演进或本地调试参考。详见各项行内说明。如需启用，需同步修改对应消费代码。
 */

return [

    // ════════════════════════════════════════════════════════════
    // ── Redis 配置 ──
    // ════════════════════════════════════════════════════════════
    // Redis 在本项目中承担：缓存、限流计数、熔断器状态、防击穿分布式锁、请求合并状态。
    // 由 lib/CacheStoreFactory.php 统一管理：优先尝试 Redis，连接失败则自动降级到文件缓存
    // （lib/FileCacheStore.php），因此即使不配置 Redis 项目仍可运行，仅性能/一致性略降。
    // 消费位置：lib/RedisCacheStore.php（构造函数读取 host/port/password/db/prefix/timeout）。
    'redis' => [
        'host'     => '127.0.0.1',  // ✅ Redis 主机地址；默认值 '127.0.0.1'
        'port'     => 6379,         // ✅ Redis 端口；默认值 6379
        'password' => '',           // ✅ Redis 访问密码；无密码留空。默认值 ''
        'db'       => 0,            // ✅ 使用的 Redis 库编号（0~15）；默认值 0。多实例部署可用不同 db 隔离
        'prefix'   => 'fa:',        // ✅ key 前缀，用于多实例/多项目隔离；默认值 'fa:'
                                     //    实际 key 形如 fa:cache:xxx、fa:rl:global、cb:xueqiu 等
        'timeout'  => 2.0,          // ✅ 连接超时（秒，浮点）；默认值 2.0。设过小可能导致高负载下误判不可用
    ],

    // ════════════════════════════════════════════════════════════
    // ── 限流配置 ──
    // ════════════════════════════════════════════════════════════
    // 限流由 SecurityAudit.php 实现，采用「IP + endpoint」与「全局」两级计数。
    'rate_limit' => [
        // ❌ 未生效（死配置）：以下四项当前没有任何代码读取。
        //    各 API 入口（market_api.php、fund_*.php、stock_*.php 等）在调用
        //    SecurityAudit::init() 时均以硬编码参数传入选址，例如
        //    market_api.php: SecurityAudit::init(['endpoint'=>'market_api','rate_limit'=>40])。
        //    若希望由配置统一控制，需修改各入口从 AppConfig 读取后再传入。
        'default_limit'  => 60,     //    预期：每 IP+endpoint 每窗口最大请求数（SecurityAudit::DEFAULT_RATE_LIMIT=60）
        'default_window' => 60,     //    预期：窗口时间（秒）（SecurityAudit::DEFAULT_RATE_WINDOW=60）

        'global_limit'   => 500,    //    预期：全站每窗口最大请求数（SecurityAudit::GLOBAL_RATE_LIMIT=500）
        'global_window'  => 60,     //    预期：全局限流窗口（秒）（SecurityAudit::GLOBAL_RATE_WINDOW=60）

        // ✅ 已生效：仅 ai_api.php 读取这两项，用于 AI SSE 接口的独立限流。
        //    读取位置：ai_api.php 第 16~17 行 AppConfig::get('rate_limit', [])。
        'ai_limit'       => 10,     //    AI 接口每窗口最大请求数；未配置时 fallback 30
        'ai_window'      => 60,     //    AI 限流窗口（秒）；未配置时 fallback 60
    ],

    // ════════════════════════════════════════════════════════════
    // ── 熔断器配置 ──
    // ════════════════════════════════════════════════════════════
    // 各数据源独立熔断：连续失败达阈值即「熔断(open)」，冷却期内直接拒绝请求，
    // 冷却结束后进入「半开(half_open)」放行一次试探，成功则恢复、失败则重新熔断。
    // 消费位置：lib/CircuitBreaker.php 构造函数，AppConfig::get("circuit_breaker.{$source}")。
    // 状态存储：优先 Redis（key 形如 cb:xueqiu），无 Redis 时降级到系统临时目录的 JSON 文件。
    // 未配置某数据源时，回退到 CircuitBreaker::SOURCE_DEFAULTS 内置默认值（即下方数值）。
    'circuit_breaker' => [
        // 每项：failure_threshold=连续失败多少次触发熔断；cooldown=熔断冷却时间（秒）
        'xueqiu'    => ['failure_threshold' => 3, 'cooldown' => 60],  // ✅ 雪球；默认 3 次 / 60s
        'eastmoney' => ['failure_threshold' => 5, 'cooldown' => 30],  // ✅ 东方财富；默认 5 次 / 30s
        'ashare'    => ['failure_threshold' => 3, 'cooldown' => 60],  // ✅ Ashare(Python 腾讯/新浪)；默认 3 次 / 60s
        'fund'      => ['failure_threshold' => 5, 'cooldown' => 30],  // ✅ 东方财富基金；默认 5 次 / 30s
    ],

    // ════════════════════════════════════════════════════════════
    // ── 缓存 TTL 配置（单位：秒）──
    // ════════════════════════════════════════════════════════════
    // 覆盖各服务类的内置默认 TTL。采用 array_merge 策略：配置值优先，缺项回退到类常量。
    //   · 行情相关：lib/MarketDataService.php（CACHE_TTL 常量）
    //   · 基金相关：lib/FundService.php（CACHE_TTL 常量）
    // 两个类合并同一份 cache_ttl 配置，因此只需在此处统一维护。
    'cache_ttl' => [
        // ── MarketDataService（股票行情）──
        'quote'          => 10,     // ✅ 实时行情报价；默认 10s
        'kline_min'      => 60,     // ✅ 分钟级 K 线（1m/5m/15m/30m/60m）；默认 60s
        'kline_day'      => 300,    // ✅ 日线/周线/月线 K 线；默认 300s
        'hot_stock'      => 60,     // ✅ 雪球热度榜；默认 60s
        'screener'       => 120,    // ✅ 条件选股；默认 120s
        'fundx'          => 180,    // ✅ 动态资讯；默认 180s
        'stock_flow'     => 30,     // ✅ 个股资金流向；默认 30s
        'sector_flow'    => 60,     // ✅ 板块资金流向；默认 60s
        'hot_stocks'     => 30,     // ✅ 热门股票资金榜；默认 30s

        // ── FundService（基金）──
        'estimate'       => 10,     // ✅ 基金实时估值（盘中短缓存）；默认 10s
        'batch_estimate' => 10,     // ✅ 批量基金估值；默认 10s
        'info'           => 300,    // ✅ 基金详情；默认 300s
        'search'         => 600,    // ✅ 基金搜索；默认 600s
        'rank'           => 300,    // ✅ 基金排行；默认 300s
        'history'        => 300,    // ✅ 历史净值；默认 300s
    ],

    // ════════════════════════════════════════════════════════════
    // ── 缓存降级配置 ──
    // ════════════════════════════════════════════════════════════
    // 用于上游故障时的「负缓存 + 防击穿 + stale 兜底」三段式降级，避免故障穿透与缓存雪崩。
    // 消费位置：lib/MarketDataService.php 与 lib/FundService.php 构造函数。
    'cache_degradation' => [
        'negative_cache_ttl' => 10,   // ✅ 负缓存 TTL（秒）：上游失败时把「空/错误结果」短暂缓存，
                                      //    期间直接返回，避免反复轰击上游。默认 10s（NEGATIVE_CACHE_TTL）
        'stale_window'       => 600,  // ⚠️ 预留未接线：当前无代码读取此项。
                                      //    预期用途为 stale-while-revalidate 的最大有效窗口（秒），
                                      //    即过期缓存仍可兜底返回的最长时间。如需启用需在服务层补充读取逻辑。
        'stampede_lock_ttl'  => 5,    // ✅ 防击穿互斥锁超时（秒）：缓存重建时只允许一个请求回源，
                                      //    其余请求等待。锁超过该时间自动释放，防进程崩溃死锁。默认 5s（STAMPEDE_LOCK_TTL）
        'stampede_wait_ms'   => 500,  // ✅ 防击穿等待时间（毫秒）：非持锁请求等待缓存重建的最长时间，
                                      //    超时则按降级策略返回。默认 500ms（STAMPEDE_WAIT_MS）
    ],

    // ════════════════════════════════════════════════════════════
    // ── AI 配置 ──
    // ════════════════════════════════════════════════════════════
    // AI 采用多渠道 SSE 流式转发。消费位置：ai_api.php（AI 代理入口）。
    'ai' => [
        // ✅ 默认渠道名，需与下方 channels 中的某个键匹配；为空或不存在时 ai_api.php 直接报错退出
        'default_channel' => 'deepseek',  //    可选值：deepseek | openai（自定义渠道键名亦可）

        // ✅ 并发流限制（基于系统临时目录的文件锁实现，见 ai_api.php acquireAIStreamSlot）
        'max_concurrent_per_ip' => 2,     //    每 IP 最多同时进行的 AI 流数；未配置 fallback 2
        'max_concurrent_global' => 10,    //    全局最多同时进行的 AI 流数；未配置 fallback 10

        // ✅ cURL 超时配置（秒）
        'timeout'         => 300,         //    请求整体超时（含推理模型思考时间，需足够大）；fallback 300
        'connect_timeout' => 15,          //    TCP 连接超时；fallback 15

        // ✅ 并发锁过期阈值（秒）：用于清理僵尸锁文件（进程异常退出未释放的槽位）。
        //    应大于 timeout，否则可能误清理仍在运行的请求。fallback 310
        'stale_threshold' => 310,

        // ⚠️ 预留未接线：ai_api.php 当前为纯透传上游 SSE，未实现服务端心跳发送逻辑，
        //    修改此项不会生效。预期用途：长连接期间定时发送 keepalive 事件，防止中间
        //    代理/CDN 因空闲断连。设为 0 表示禁用。如需启用需在 ai_api.php 补充心跳循环。
        'heartbeat_interval' => 15,

        // ✅ 消息内容限制：覆盖 SecurityAudit 内置常量，用于校验前端传入的消息体。
        //    读取位置：ai_api.php 第 165~166 行。
        'max_message_length' => 50000,    //    单条消息最大字符数；fallback SecurityAudit::MAX_MESSAGE_LENGTH=50000
        'max_message_count'  => 100,      //    单次会话最大消息条数；fallback SecurityAudit::MAX_MESSAGE_COUNT=100

        // ── 渠道定义 ──
        // 每个渠道需提供 api_url / api_key / model 三项，缺任一项 ai_api.php 报错退出。
        // ⚠️ api_key 属于敏感信息，必须在 config.php 中填写，切勿提交到版本库！
        'channels' => [
            'deepseek' => [
                'name'    => 'DeepSeek',                              // ✅ 渠道显示名（仅注释用途，代码未强依赖）
                'api_url' => 'https://api.deepseek.com/chat/completions', // ✅ 上游 Chat Completions 端点
                'api_key' => '',               // ← 必填，DeepSeek API Key
                'model'   => 'deepseek-chat',   // ✅ 调用的模型名
            ],
            'openai' => [
                'name'    => 'OpenAI兼容',                            // ✅ 任意兼容 OpenAI 协议的端点均可
                'api_url' => '',               // ← 必填，如 https://api.example.com/v1/chat/completions
                'api_key' => '',               // ← 必填
                'model'   => '',               // ← 必填，如 gpt-4o、kimi-k2.6
            ],
        ],
    ],

    // ════════════════════════════════════════════════════════════
    // ── Python 数据服务（Phase 3 预留）──
    // ════════════════════════════════════════════════════════════
    // ⚠️ 预留未接线：当前无任何代码读取此项。Ashare 数据仍通过 exec() 调用
    //    get_stock_data.py（见 lib/AshareBridge.php）。此配置为未来「Python 长驻
    //    HTTP 服务」方案预留，届时将以 HTTP 调用替代 exec() 以降低进程开销。
    'python_service' => [
        'enabled' => false,              //    预期：是否启用长驻服务模式
        'url'     => 'http://127.0.0.1:8900', //    预期：长驻服务监听地址
    ],

    // ════════════════════════════════════════════════════════════
    // ── Python CLI ──
    // ════════════════════════════════════════════════════════════
    // ✅ 已生效：lib/AshareBridge.php resolvePythonBinary() 读取。
    //    指定执行 get_stock_data.py 的 Python 解释器；留空则自动探测能运行 Ashare
    //    （依赖 pandas/requests）的解释器，探测顺序随平台不同：
    //      Windows: py -3.10 → python
    //      Linux:   /www/server/pyporject_evn/versions/3.10.11/bin/python3 → python3 → python
    //    可填绝对路径（如 /usr/bin/python3）或命令片段（如 py -3.10）。
    'python' => [
        'binary' => '',
    ],

    // ════════════════════════════════════════════════════════════
    // ── 安全配置 ──
    // ════════════════════════════════════════════════════════════
    'security' => [
        // ❌ 未生效（死配置）：SecurityAudit.php 使用硬编码类常量 TRUST_PROXY=false，
        //    ai_api.php 第 50 行也直接引用 SecurityAudit::TRUST_PROXY，并未读取本配置。
        //    预期用途：设为 true 后，客户端 IP 从 X-Forwarded-For / X-Real-IP 头读取
        //    （仅在确认部署了可信反向代理时启用，否则可被伪造绕过限流）。
        //    如需启用，需修改 SecurityAudit.php 将常量改为从 AppConfig::get('security.trust_proxy') 读取。
        'trust_proxy' => false,

        // ❌ 未生效（预留）：当前 SecurityAudit::init() 的限流参数由各入口硬编码传入，
        //    此处注释项不会被读取。保留作为「未来统一从配置读取限流」的示例。
        // 'rate_limit' => [
        //     'default_limit'  => 60,
        //     'default_window' => 60,
        //     'global_limit'   => 500,
        //     'global_window'  => 60,
        // ],
    ],
];
