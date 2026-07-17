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
        //    读取位置：ai_api.php 第 13 行 AppConfig::get('rate_limit', [])，第 17~18 行传入 SecurityAudit::init()。
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
        'eastmoney_dividend' => ['failure_threshold' => 3, 'cooldown' => 60], // ✅ 东方财富分红公司行动
        'eastmoney_fund_dividend' => ['failure_threshold' => 3, 'cooldown' => 60], // ✅ 东方财富基金分红事件源；默认 3 次 / 60s
        'csindex' => ['failure_threshold' => 3, 'cooldown' => 60], // ✅ 中证指数官网历史表现；独立熔断，默认 3 次 / 60s
        'eastmoney_news' => ['failure_threshold' => 3, 'cooldown' => 60], // ✅ 东方财富公开新闻搜索 PoC；独立熔断
        'eastmoney_f10_news' => ['failure_threshold' => 3, 'cooldown' => 60], // ✅ 东方财富个股 F10 公司资讯；独立熔断
        'eastmoney_fast_news' => ['failure_threshold' => 3, 'cooldown' => 60], // ✅ 东方财富 7×24 快讯；与搜索接口独立熔断
        'google_news_rss' => ['failure_threshold' => 3, 'cooldown' => 120], // ✅ 海外基金媒体新闻 RSS；独立熔断
        'eastmoney_fund_announcements' => ['failure_threshold' => 3, 'cooldown' => 60], // ✅ 东方财富基金公告；独立熔断
        'eastmoney_announcements' => ['failure_threshold' => 3, 'cooldown' => 60], // ✅ 东方财富股票公告列表/正文；独立熔断
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
        'stock_search'   => 600,    // ✅ 股票代码/名称/拼音关键词搜索；默认 600s
        'kline_min'      => 60,     // ✅ 分钟级 K 线（1m/5m/15m/30m/60m）；默认 60s
        'kline_day'      => 300,    // ✅ 日线/周线/月线 K 线；默认 300s
        'hot_stock'      => 60,     // ✅ 雪球热度榜；默认 60s
        'screener'       => 120,    // ✅ 条件选股；默认 120s
        'fundx'          => 180,    // ✅ 动态资讯；默认 180s
        'stock_flow'     => 30,     // ✅ 个股资金流向；默认 30s
        'sector_flow'    => 60,     // ✅ 板块资金流向；默认 60s
        'hot_stocks'     => 30,     // ✅ 热门股票资金榜；默认 30s
        'market_breadth' => 20,     // ✅ 市场宽度/涨跌家数/近似涨跌停统计；默认 20s
        'news_asset'     => 60,     // ✅ 指定股票/基金新闻；默认 60s
        'news_market'    => 60,     // ✅ 市场关键词热点新闻；默认 60s
        'news_sentiment' => 90,     // ✅ 标题情绪快照；默认 90s
        'announcement_list' => 180, // ✅ 股票公告列表与事件筛选；默认 180s
        'announcement_detail' => 86400, // ✅ 单篇股票公告正文；默认 86400s

        // ── FundService（基金）──
        'estimate'       => 10,     // ✅ 基金实时估值（盘中短缓存）；默认 10s
        'batch_estimate' => 10,     // ✅ 批量基金估值；默认 10s
        'info'           => 300,    // ✅ 基金详情；默认 300s
        'search'         => 600,    // ✅ 基金搜索；默认 600s
        'rank'           => 300,    // ✅ 基金排行；默认 300s
        'history'        => 300,    // ✅ 历史净值；默认 300s
        'history_window' => 300,    // ✅ 历史净值定点窗口（基金分红事件前后净值图）；默认 300s
        'nav_batch'      => 300,    // ✅ 批量最新净值（基金分红日历 FundMNFInfo 批量）；默认 300s
        'index_profile'    => 3600, // ✅ 基金跟踪指数画像（跟踪指数代码/名称等）；默认 3600s
        'dividend_history' => 300,  // ✅ 基金分红历史；默认 300s
        'dividend_profile' => 300,  // ✅ 基金分红档案（直接事件+公告+目标 ETF）；默认 300s
        'documents'        => 1800, // ✅ 基金公告/文档列表；默认 1800s
        'detail'         => 3600,   // ✅ 基金 F10 详情（聚合工具复用）；默认 3600s
        'performance_stats' => 300, // ✅ fa_get_fund_performance_stats 长历史统计；默认 300s
        'trade_rules'    => 300,    // ✅ fa_get_fund_trade_rules 交易规则；默认 300s
        'exposure'       => 3600,   // ✅ fa_get_fund_holdings_or_index_exposure 风格暴露；默认 3600s
        'screen'         => 300,    // ✅ fa_screen_funds 候选召回（job 级 batchFetch 缓存）；默认 300s
        'score'          => 120,    // ✅ fa_score_funds 评分结果；默认 120s
        'holdings'       => 3600,   // ✅ fa_get_fund_holdings 基金十大持仓+占净值比；默认 3600s
        'index_kline'    => 300,    // ✅ 跟踪指数 K 线（跟踪误差计算的基准序列）；默认 300s
    ],

    // ── 股票分红日历 ──
    'dividend' => [
        'enabled'             => true,
        'provider'            => 'eastmoney', // 预留可替换 Provider；首版仅 eastmoney
        'default_window_days' => 14,
        'max_window_days'     => 60,
        'quote_batch_size'    => 200,
        'auto_refresh_seconds' => 600, // 交易时段前端静默刷新；限制为 300–1800s
        'calendar_ttl'        => 900,
        'detail_ttl'          => 1800,
        'negative_cache_ttl'  => 20,
        'stampede_lock_ttl'   => 5,
        'stampede_wait_ms'    => 500,
    ],

    // ── 基金分红日历 ──
    // 读取位置：lib/FundDividendService.php 构造函数。未配置时回退类内默认值。
    'fund_dividend' => [
        'enabled'             => true,    // ✅ 基金分红日历总开关
        'default_window_days' => 14,      // ✅ 默认 14 日窗口
        'max_window_days'     => 60,      // ✅ 最大 60 日窗口
        'nav_batch_size'      => 50,      // ✅ FundMNFInfo 批量净值每请求上限；默认 50
        'auto_refresh_seconds' => 900,    // ✅ 基金模式前端静默刷新；不受 A 股交易时段限制；默认 900s
        'calendar_ttl'        => 900,     // ✅ 事件列表缓存；默认 900s
        'detail_ttl'          => 1800,    // ✅ 基金分红详情缓存；默认 1800s
        'type_map_ttl'        => 86400,   // ✅ fundcode_search.js 类型映射缓存；默认 86400s
        'nav_ttl'             => 300,     // ✅ 批量净值缓存（覆盖 FundService::CACHE_TTL.nav_batch）；默认 300s
        'negative_cache_ttl'  => 20,      // ✅ 负缓存 TTL；默认 20s
        'stampede_lock_ttl'   => 5,       // ✅ 防击穿锁超时；默认 5s
        'stampede_wait_ms'    => 500,     // ✅ 防击穿等待；默认 500ms
    ],

    // ── 新闻舆情 PoC ──
    // 东方财富公开搜索仅作为可替换 Provider；对外严格只返回标题、来源、时间、链接。
    'news' => [
        'provider' => 'eastmoney_composite',
        'default_market_keywords' => ['A股', '沪指', '基金市场'],
        'max_queries' => 4,
        'fast_news_page_size' => 100,
        'fund_fallback_enabled' => true,
        'fund_google_news_rss' => true,
        'fund_announcements' => true,
        'fund_rss_max_queries' => 2,
    ],

    // ── 股票公告与公司事件 ──
    // 读取位置：lib/AnnouncementService.php；首版 Provider 为东方财富公开网页接口。
    'announcement' => [
        'provider' => 'eastmoney',       // ✅ 可替换 Provider 标识；首版仅 eastmoney
        'max_scan_pages' => 3,           // ✅ 单次筛选最多扫描上游页数；每页最多 100 条
        'upstream_page_size' => 100,     // ✅ 上游每页数量；范围 20–100
        'detail_content_limit' => 12000, // ✅ 正文默认返回字符数；最大 20000
        'list_stale_ttl' => 1800,        // ✅ 列表过期后的陈旧兜底窗口（秒）
        'detail_stale_ttl' => 604800,    // ✅ 正文过期后的陈旧兜底窗口（秒，7 日）
        'negative_cache_ttl' => 10,      // ✅ 上游失败负缓存（秒）
    ],

    // ════════════════════════════════════════════════════════════
    // ── 基金研究聚合工具配置 ──
    // ════════════════════════════════════════════════════════════
    // 控制 6 个新增只读聚合工具的行为参数。
    // 读取位置：lib/FundService.php 构造函数（$researchConfig）。
    // 未配置项回退 FundService 内置默认值。
    'fund_research' => [
        'target_history_days'   => 500,   // ✅ fa_get_fund_performance_stats 目标历史交易日行数（分页拉取上限）；默认 500
        'max_screen_candidates' => 20,    // ✅ fa_screen_funds 最大去重候选返回数；默认 20
        'max_score_candidates'  => 20,    // ✅ fa_score_funds 最大评分候选数；默认 20
        'max_parallel_workers'  => 4,     // ✅ 聚合工具内部 curl_multi 并发数（历史分页/多关键词搜索并行）；默认 4
        'retry_network_errors'  => true,  // ✅ 历史分页网络错误是否重试 1 次；默认 true
        'screen_page_size'      => 50,    // ✅ fa_screen_funds 每源排行/搜索样本数；默认 50
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

        // ✅ SSE 心跳间隔（秒）：长工具调用或上游流式空闲期间发送 keepalive 注释行，
        //    防止浏览器/反代/CDN 因长时间无字节输出而断连；设为 0 表示禁用。
        'heartbeat_interval' => 15,

        // ✅ 消息内容限制：覆盖 SecurityAudit 内置常量，用于校验前端传入的消息体。
        //    读取位置：ai_api.php 第 166~167 行。
        //    注意：前端基金 AI 上下文上限为 255000（main.js AI_CONTEXT_LIMIT），
        //    若启用大上下文场景，此处 max_message_length 需略大于该值以容纳系统提示。
        'max_message_length' => 50000,    //    单条消息最大字符数；fallback SecurityAudit::MAX_MESSAGE_LENGTH=50000
        'max_message_count'  => 100,      //    单次会话最大消息条数；fallback SecurityAudit::MAX_MESSAGE_COUNT=100

        // ✅ AI 工具调用智能体：服务端按 OpenAI Chat Completions tools/tool_calls 协议编排。
        //    启用后，ai_api.php 会先让模型选择只读研究工具，执行本地行情/基金/雪球服务，
        //    再将工具结果回填给模型生成最终 SSE 回复。若渠道 supports_tools=false，则回退纯流式对话。
        'tool_agent' => [
            'enabled' => true,
            'max_tool_rounds' => 10,            // 单次用户请求最多工具调用轮次
            'max_tool_calls_per_round' => 8,    // 每轮最多执行的工具调用数
            'max_tool_calls_total' => 64,       // 单次请求最多真实执行工具数；防止循环或上下文膨胀
            'max_deep_dive_candidates' => 10,   // 市场扫描类请求最多深挖候选数（每个候选会查行情/指标/资金流）
            'tool_timeout' => 180,              // 非流式工具决策/单工具执行耗时预算（秒）；基金长历史统计可能超过 60s
            'tool_output_char_limit' => 60000,  // 单个工具输出回填给模型的最大字符数
            'parallel_tool_calls' => true,      // 允许模型一次请求多个工具；配置 token+endpoint 后用 curl_multi 内部执行；端点故障时停止工具循环而不切换工具
            'internal_exec_token' => '',        // 内部工具执行鉴权 token；生产环境建议填 32+ 位随机字符串；未配置内部派发时使用同进程执行
            'internal_exec_endpoint' => '',     // 内部工具执行端点 URL；本地 PHP 开发服务器必须另启独立端口，不能自调用主站单线程端口
            'internal_exec_host' => '',         // 可选：仅本机 127.0.0.1 请求必须指定 vhost 时填写；错误填写可能触发 HTTP->HTTPS 301
            'expose_tool_trace' => true,        // 向前端发送 tool_status SSE 事件用于展示进度
            'emit_agent_events' => true,        // 发送 run_started/tool_call_finished/run_finished 等结构化智能体事件
            'suppress_reasoning_content' => false, // 默认向前端透传上游 reasoning_content 推理流；设为 true 可隐藏
            'auto_prefetch' => false,           // 已废弃：禁止服务端自动兜底预取；工具调用只能由模型 tools/tool_calls 主动发起
            'stream_after_tool_round' => true,  // 模型主动调用工具后，观察结果回填给模型继续自行决策或最终回答
            'agent_profile' => '',              // 留空自动识别；可强制 advisor/market_scanner/fund_researcher/risk_reviewer
            'trace_enabled' => false,           // 是否将每次 run 的 trace 落盘为 JSONL；默认关闭
            'trace_log_path' => '',             // trace 落盘路径；留空时使用系统临时目录
            'max_tokens' => 8192,               // 最终回答额度；MiMo-V2.5 自动映射为 max_completion_tokens（思考+回答共用）
            'tool_decision_max_tokens' => 4096, // 工具决策轮额度；MiMo-V2.5 自动映射为 max_completion_tokens，避免推理/参数被截断
        ],

        // ── 渠道定义 ──
        // 每个渠道需提供 api_url / api_key / model 三项，缺任一项 ai_api.php 报错退出。
        // ⚠️ api_key 属于敏感信息，必须在 config.php 中填写，切勿提交到版本库！
        'channels' => [
            'deepseek' => [
                'name'    => 'DeepSeek',                              // ✅ 渠道显示名（仅注释用途，代码未强依赖）
                'api_url' => 'https://api.deepseek.com/chat/completions', // ✅ 上游 Chat Completions 端点
                'api_key' => '',               // ← 必填，DeepSeek API Key
                'model'   => 'deepseek-chat',   // ✅ 调用的模型名
                'supports_tools' => true,       // ✅ 是否支持 OpenAI-compatible tools/tool_calls
            ],
            'openai' => [
                'name'    => 'OpenAI兼容',                            // ✅ 任意兼容 OpenAI 协议的端点均可
                'api_url' => '',               // ← 必填，如 https://api.example.com/v1/chat/completions
                'api_key' => '',               // ← 必填
                'model'   => '',               // ← 必填，如 gpt-4o、kimi-k2.6
                'supports_tools' => true,       // ✅ 不支持工具调用的兼容端点请设为 false
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
        //    ai_api.php 第 51 行也直接引用 SecurityAudit::TRUST_PROXY，并未读取本配置。
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
