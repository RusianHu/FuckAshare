<?php
/**
 * AIFinanceToolCatalog — read-only financial research tools exposed to models.
 */

require_once __DIR__ . '/AIToolSchema.php';

class AIFinanceToolCatalog
{
    public static function definitions(): array
    {
        return array_merge(
            self::stockMarketTools(),
            self::fundTools(),
            self::fundResearchTools(),
            self::researchTools()
        );
    }

    public static function stockMarketTools(): array
    {
        $stockCode = self::stockCodeSchema();

        return [
            'fa_normalize_stock_code' => AIToolSchema::tool(
                'fa_normalize_stock_code',
                'Normalize a stock code and return formats for Eastmoney, Ashare, Xueqiu, and display use.',
                ['code' => $stockCode]
            ),
            'fa_get_stock_quote' => AIToolSchema::tool(
                'fa_get_stock_quote',
                'Get real-time quote data for up to 20 stocks using the server-side market data service.',
                [
                    'codes' => ['type' => 'array', 'items' => $stockCode, 'minItems' => 1, 'maxItems' => 20, 'description' => 'Stock codes to query.'],
                    'source' => AIToolSchema::nullableEnum(['auto', 'eastmoney', 'ashare', 'xueqiu'], 'Preferred data source. Use auto unless the user asks for a source.'),
                    'fallback' => ['type' => ['boolean', 'null'], 'description' => 'Whether to allow source fallback. Use true by default.'],
                ]
            ),
            'fa_get_stock_kline' => AIToolSchema::tool(
                'fa_get_stock_kline',
                'Get stock K-line/candlestick data for technical research.',
                [
                    'code' => $stockCode,
                    'frequency' => AIToolSchema::nullableEnum(['1m', '5m', '15m', '30m', '60m', '1d', '1w', '1M'], 'K-line frequency. Default 1d.'),
                    'count' => AIToolSchema::nullableInteger('Number of bars to fetch. Default 120, max 500.', 1, 500),
                    'end_date' => AIToolSchema::nullableString('Optional end date in YYYY-MM-DD format.'),
                    'source' => AIToolSchema::nullableEnum(['auto', 'eastmoney', 'ashare', 'xueqiu'], 'Preferred data source. Use auto by default.'),
                ]
            ),
            'fa_get_stock_flow' => AIToolSchema::tool(
                'fa_get_stock_flow',
                'Get Eastmoney capital flow data for one stock.',
                [
                    'code' => $stockCode,
                    'limit' => AIToolSchema::nullableInteger('Maximum flow records. Use 0 for service default.', 0, 1000),
                ]
            ),
            'fa_get_sector_flow' => AIToolSchema::tool(
                'fa_get_sector_flow',
                'Get sector/industry/concept/theme/region capital flow ranking.',
                [
                    'key' => AIToolSchema::nullableEnum(['f62', 'f164', 'f174'], 'Sort key: f62 today, f164 5-day, f174 10-day.'),
                    'type' => AIToolSchema::nullableEnum(['industry', 'concept', 'theme', 'region'], 'Sector type.'),
                ]
            ),
            'fa_get_hot_stocks' => AIToolSchema::tool(
                'fa_get_hot_stocks',
                'Get Eastmoney hot stocks ranked by capital flow or turnover fields.',
                [
                    'page' => AIToolSchema::nullableInteger('Page number.', 1, 100),
                    'page_size' => AIToolSchema::nullableInteger('Page size.', 1, 200),
                    'sort' => AIToolSchema::nullableEnum(['f62', 'f184', 'f66', 'f72', 'f6', 'f3'], 'Sort field. Default f62.'),
                    'order' => AIToolSchema::nullableInteger('Sort order: 1 descending in existing API conventions, -1 ascending when supported.', -1, 1),
                ]
            ),
            'fa_get_market_breadth' => AIToolSchema::tool(
                'fa_get_market_breadth',
                'Get A-share market breadth, major index overview, advance/decline counts, and optional approximate limit-up/limit-down breadth statistics.',
                [
                    'scope' => AIToolSchema::nullableEnum(['a_share', 'sh', 'sz', 'core_indices'], 'Market scope. Default a_share.'),
                    'include_limit_stats' => ['type' => ['boolean', 'null'], 'description' => 'Whether to include approximate limit-up/limit-down statistics. Default true.'],
                    'include_index_quotes' => ['type' => ['boolean', 'null'], 'description' => 'Whether to include major index quotes. Default true.'],
                ]
            ),
            'fa_get_upcoming_dividends' => AIToolSchema::tool(
                'fa_get_upcoming_dividends',
                'Scan upcoming A-share dividend record-date events, merge current quotes, and deterministically calculate one-off gross and personal after-tax cash yields. Use this first for dividend calendar, record date, ex-dividend date, dividend capture, or near-term high-dividend questions. This is an event yield, not an annualized return.',
                [
                    'start_date' => AIToolSchema::nullableString('Start date in YYYY-MM-DD. Default today in Asia/Shanghai.'),
                    'days' => AIToolSchema::nullableInteger('Inclusive calendar window length. Default 14, max 60.', 1, 60),
                    'market' => AIToolSchema::nullableEnum(['all', 'sh', 'sz', 'bj'], 'A-share market filter. Default all.'),
                    'confirmed_only' => AIToolSchema::nullableBoolean('Only include implemented/confirmed distributions. Default true.'),
                    'holding_period' => AIToolSchema::nullableEnum(['within_1m', '1m_to_1y', 'over_1y'], 'Personal investor holding-period tax estimate. Default within_1m.'),
                    'min_gross_yield' => AIToolSchema::nullableNumber('Minimum one-off gross cash yield percent. Default 0.', 0, 100),
                    'sort_by' => AIToolSchema::nullableEnum(['gross_yield', 'net_yield', 'record_date', 'cash_per_share'], 'Deterministic sort field. Default gross_yield.'),
                    'order' => AIToolSchema::nullableEnum(['asc', 'desc'], 'Sort direction. Default desc.'),
                    'limit' => AIToolSchema::nullableInteger('Maximum candidates returned. Default 20, max 50.', 1, 50),
                ]
            ),
            'fa_get_stock_dividend_profile' => AIToolSchema::tool(
                'fa_get_stock_dividend_profile',
                'Get one A-share stock current/upcoming dividend event, normalized cash-dividend history, current quote, and deterministic history summary. Use this to assess dividend consistency after discovering a candidate.',
                [
                    'code' => $stockCode,
                    'years' => AIToolSchema::nullableInteger('History window in years. Default 10, max 20.', 1, 20),
                    'holding_period' => AIToolSchema::nullableEnum(['within_1m', '1m_to_1y', 'over_1y'], 'Personal investor holding-period tax estimate. Default within_1m.'),
                ]
            ),
            'fa_get_xueqiu_hot_stock' => AIToolSchema::tool(
                'fa_get_xueqiu_hot_stock',
                'Get Xueqiu hot stock ranking for attention and sentiment context.',
                [
                    'type' => AIToolSchema::nullableEnum(['10', '11', '12', '13', '14'], 'Xueqiu hot list type. Default 10.'),
                    'size' => AIToolSchema::nullableInteger('Number of items.', 1, 100),
                ]
            ),
            'fa_run_xueqiu_screener' => AIToolSchema::tool(
                'fa_run_xueqiu_screener',
                'Run Xueqiu stock screener for market-wide candidate discovery.',
                [
                    'page' => AIToolSchema::nullableInteger('Page number.', 1, 100),
                    'size' => AIToolSchema::nullableInteger('Page size.', 1, 100),
                    'order_by' => AIToolSchema::nullableEnum(['percent', 'amount', 'volume', 'turnover_rate', 'volume_ratio', 'market_capital', 'float_market_capital', 'pe_ttm', 'pb', 'roe_ttm', 'dividend_yield', 'followers', 'limitup_days'], 'Screener order field.'),
                    'order' => AIToolSchema::nullableEnum(['asc', 'desc'], 'Sort direction.'),
                    'market' => AIToolSchema::nullableEnum(['CN', 'HK', 'US'], 'Market. Default CN.'),
                    'type' => AIToolSchema::nullableEnum(['11', '82', '30', '', 'ashare', 'hk', 'us', 'sh_sz', 'sh', 'sz', 'bj', 'kcb', 'cyb'], 'Xueqiu screener type. Default sh_sz.'),
                ]
            ),
            'fa_get_xueqiu_feed' => AIToolSchema::tool(
                'fa_get_xueqiu_feed',
                'Get Xueqiu public fund/feed items exposed by the existing data service.',
                [
                    'page' => AIToolSchema::nullableInteger('Page number.', 1, 100),
                    'source' => AIToolSchema::nullableString('Optional Xueqiu source key. Usually null.'),
                    'last_id' => AIToolSchema::nullableInteger('Optional pagination last id.', 0, PHP_INT_MAX),
                ]
            ),
        ];
    }

    public static function fundTools(): array
    {
        $fundCode = self::fundCodeSchema();

        return [
            'fa_search_funds' => AIToolSchema::tool(
                'fa_search_funds',
                'Search funds by code, name, or pinyin keyword.',
                ['keyword' => ['type' => 'string', 'description' => 'Fund search keyword, max 100 chars.']]
            ),
            'fa_get_fund_info' => AIToolSchema::tool(
                'fa_get_fund_info',
                'Get fund profile information for up to 20 funds.',
                ['codes' => ['type' => 'array', 'items' => $fundCode, 'minItems' => 1, 'maxItems' => 20, 'description' => 'Fund codes.']]
            ),
            'fa_get_fund_estimate' => AIToolSchema::tool(
                'fa_get_fund_estimate',
                'Get real-time estimate for one or more funds.',
                ['codes' => ['type' => 'array', 'items' => $fundCode, 'minItems' => 1, 'maxItems' => 20, 'description' => 'Fund codes.']]
            ),
            'fa_get_fund_history' => AIToolSchema::tool(
                'fa_get_fund_history',
                'Get historical NAV records for a fund.',
                [
                    'code' => $fundCode,
                    'page' => AIToolSchema::nullableInteger('Page number.', 1, 200),
                    'page_size' => AIToolSchema::nullableInteger('Page size.', 5, 100),
                ]
            ),
            'fa_get_fund_rank' => AIToolSchema::tool(
                'fa_get_fund_rank',
                'Get fund ranking samples for peer comparison.',
                [
                    'type' => AIToolSchema::nullableEnum(['all', 'stock', 'mixed', 'bond', 'index', 'qdii', 'fof'], 'Fund category.'),
                    'period' => AIToolSchema::nullableEnum(['day', 'week', 'month', 'quarter', 'half_year', 'year', 'two_year', 'three_year', 'this_year', 'since'], 'Ranking period.'),
                    'page' => AIToolSchema::nullableInteger('Page number.', 1, 1000),
                    'page_size' => AIToolSchema::nullableInteger('Page size.', 5, 100),
                ]
            ),
            'fa_get_index_profile' => AIToolSchema::tool(
                'fa_get_index_profile',
                'Get fund-derived tracking index profile, benchmark, investment target, and strategy evidence for a fund.',
                ['code' => $fundCode]
            ),
            'fa_get_fund_dividend_history' => AIToolSchema::tool(
                'fa_get_fund_dividend_history',
                'Get the fund share class historical dividend event table, including record, ex-dividend, cash payment dates and cash per unit. Use for bare history; use fa_get_fund_dividend_profile for current, future-date, announcement, or ETF-linkage questions.',
                [
                    'code' => $fundCode,
                    'page' => AIToolSchema::nullableInteger('Page number.', 1, 200),
                    'page_size' => AIToolSchema::nullableInteger('Page size.', 1, 100),
                ]
            ),
            'fa_get_fund_dividend_profile' => AIToolSchema::tool(
                'fa_get_fund_dividend_profile',
                'Build a current dividend evidence profile for a fund. It checks direct dividend events, current dividend announcements, manager first-party evidence when supported, and resolves a link fund target ETF so asset-level ETF dividends are not confused with direct cash distributions to link-fund holders. Prefer this for questions about future dates, this month, whether a dividend is coming, or announcement verification.',
                [
                    'code' => $fundCode,
                    'limit' => AIToolSchema::nullableInteger('Maximum dividend events per fund. Default 10.', 1, 50),
                    'include_related' => AIToolSchema::nullableBoolean('Whether to resolve and inspect a link fund target ETF. Default true.'),
                    'include_announcements' => AIToolSchema::nullableBoolean('Whether to inspect current dividend announcements. Default true.'),
                    'announcement_limit' => AIToolSchema::nullableInteger('Maximum dividend announcements per fund. Default 5.', 1, 20),
                ]
            ),
            'fa_get_fund_documents' => AIToolSchema::tool(
                'fa_get_fund_documents',
                'Get fund announcements, reports, contracts, prospectus, dividend notices, and optional extracted document text.',
                [
                    'code' => $fundCode,
                    'page' => AIToolSchema::nullableInteger('Page number.', 1, 200),
                    'page_size' => AIToolSchema::nullableInteger('Page size.', 1, 100),
                    'doc_type' => AIToolSchema::nullableEnum(['all', 'periodic_report', 'prospectus', 'contract', 'dividend', 'other'], 'Document type filter. Default all.'),
                    'include_content' => ['type' => ['boolean', 'null'], 'description' => 'Whether to extract announcement/PDF text. Default false.'],
                    'content_limit' => AIToolSchema::nullableInteger('Maximum extracted text chars per document. Default 6000, max 20000.', 1000, 20000),
                ]
            ),
        ];
    }

    public static function fundResearchTools(): array
    {
        $fundCode = self::fundCodeSchema();
        $fundCodesArray = ['type' => 'array', 'items' => $fundCode, 'minItems' => 1, 'maxItems' => 20, 'description' => 'Fund codes.'];
        $weightItem = AIToolSchema::strictObject([
            'key' => ['type' => 'string', 'description' => 'Score dimension key, e.g. theme_fit, return_quality, drawdown_control, scale_liquidity, fee_efficiency, dividend_behavior, buyability, data_quality.'],
            'value' => ['type' => 'number', 'description' => 'Weight value; normalized server-side.'],
        ]);

        return [
            'fa_screen_funds' => AIToolSchema::tool(
                'fa_screen_funds',
                'Search and screen fund candidates by theme, aliases, fund type, purchase status, scale and ranking evidence. Use this for fund screening/recommendation tasks instead of repeatedly calling fa_search_funds with single keywords.',
                [
                    'theme' => ['type' => ['string', 'null'], 'description' => 'Theme key, e.g. dividend, low_volatility, broad_index, bond, qdii. Default null.'],
                    'keywords' => AIToolSchema::nullableArray(['type' => 'string'], 'Search keywords or aliases. For dividend theme use 红利, 高股息, 股息, 红利低波, 央企红利, 标普红利.', 0, 12),
                    'fund_types' => AIToolSchema::nullableArray(
                        ['type' => 'string', 'enum' => ['all', 'stock', 'mixed', 'bond', 'index', 'qdii', 'fof']],
                        'Fund ranking categories to inspect. Default inferred from theme.',
                        0, 7
                    ),
                    'periods' => AIToolSchema::nullableArray(
                        ['type' => 'string', 'enum' => ['month', 'quarter', 'half_year', 'year', 'two_year', 'three_year', 'this_year', 'since']],
                        'Ranking periods used as supporting evidence. Default year, half_year, this_year.',
                        0, 8
                    ),
                    'page_size' => AIToolSchema::nullableInteger('Ranking/search sample size per source. Default 50.', 10, 100),
                    'max_candidates' => AIToolSchema::nullableInteger('Maximum deduplicated candidates returned. Default 20.', 3, 50),
                    'min_scale_yuan' => AIToolSchema::nullableNumber('Optional minimum fund scale in yuan.', 0, null),
                    'include_unbuyable' => AIToolSchema::nullableBoolean('Whether to include funds that are not currently buyable or unknown. Default true.'),
                ]
            ),
            'fa_get_fund_performance_stats' => AIToolSchema::tool(
                'fa_get_fund_performance_stats',
                'Fetch paginated NAV history and calculate deterministic performance statistics (returns, drawdown, volatility, win rate, extremes) for one or more funds. Prefer this over raw fa_get_fund_history when assessing historical performance, drawdown or volatility.',
                [
                    'codes' => $fundCodesArray,
                    'target_days' => AIToolSchema::nullableInteger('Target trading-day rows. Default 500.', 20, 1500),
                    'periods' => AIToolSchema::nullableArray(
                        ['type' => 'string', 'enum' => ['1m', '3m', '6m', '1y', '2y', '3y', 'since_sample']],
                        'Periods to summarize. Default 1m,3m,6m,1y,3y,since_sample.',
                        0, 7
                    ),
                    'use_acc_nav' => AIToolSchema::nullableBoolean('Use accumulated NAV when available, better for dividend-adjusted return. Default true.'),
                    'include_recent_rows' => AIToolSchema::nullableInteger('Include latest raw NAV rows for model inspection. Default 10.', 0, 60),
                ]
            ),
            'fa_score_funds' => AIToolSchema::tool(
                'fa_score_funds',
                'Deterministically score and rank provided fund candidates using profile, performance stats, trade rules, dividend and style evidence. Must be called before giving fund recommendations to ensure reproducible ranking.',
                [
                    'codes' => $fundCodesArray,
                    'objective' => AIToolSchema::nullableEnum(['balanced', 'long_term_stable', 'low_fee_index', 'dividend_income', 'active_alpha', 'low_drawdown'], 'Ranking objective. Default balanced.'),
                    'horizon' => AIToolSchema::nullableEnum(['short', 'medium', 'long'], 'Investment horizon for scoring weights. Default long.'),
                    'risk_preference' => AIToolSchema::nullableEnum(['low', 'medium', 'high'], 'Risk preference. Default medium.'),
                    'weights' => AIToolSchema::nullableArray($weightItem, 'Optional score weights as key/value pairs. Values are normalized server-side.', 0, 8),
                    'require_buyable' => AIToolSchema::nullableBoolean('Whether unbuyable funds should receive a hard penalty. Default false.'),
                ]
            ),
            'fa_get_fund_trade_rules' => AIToolSchema::tool(
                'fa_get_fund_trade_rules',
                'Get fund purchase, redeem, limit, fee and availability rules. Use this to confirm buyability, limits and fees before recommending a fund.',
                [
                    'codes' => $fundCodesArray,
                    'include_fee_detail' => AIToolSchema::nullableBoolean('Whether to include fee fields if available. Default true.'),
                    'include_platform_status' => AIToolSchema::nullableBoolean('Whether to include buy/redeem status from available Eastmoney fields. Default true.'),
                ]
            ),
            'fa_get_fund_holdings_or_index_exposure' => AIToolSchema::tool(
                'fa_get_fund_holdings_or_index_exposure',
                'Get holdings, sector exposure, benchmark/index exposure and style/factor tags for a fund when available. Use this to deepen style profiling beyond fa_get_index_profile.',
                [
                    'code' => $fundCode,
                    'prefer' => AIToolSchema::nullableEnum(['auto', 'holdings', 'index', 'documents'], 'Preferred exposure source. Default auto.'),
                    'include_top_holdings' => AIToolSchema::nullableBoolean('Whether to include top holdings. Default true.'),
                    'include_industry' => AIToolSchema::nullableBoolean('Whether to include industry/sector exposure if available. Default true.'),
                    'include_document_evidence' => AIToolSchema::nullableBoolean('Whether to inspect latest report/prospectus text snippets. Default false.'),
                    'content_limit' => AIToolSchema::nullableInteger('Document text limit when include_document_evidence=true. Default 6000.', 1000, 20000),
                ]
            ),
            'fa_get_fund_holdings' => AIToolSchema::tool(
                'fa_get_fund_holdings',
                'Get real top stock holdings and industry exposure for a fund from the latest fund report. Use this when fa_get_fund_holdings_or_index_exposure returns empty holdings, or when fresher detailed real holdings and industry weights are needed for style analysis.',
                [
                    'code' => $fundCode,
                    'topline' => AIToolSchema::nullableInteger('Top holdings count. Default 10, max 50.', 1, 50),
                    'include_industry' => AIToolSchema::nullableBoolean('Whether to include industry exposure. Default true.'),
                ]
            ),
            'fa_research_state_summary' => AIToolSchema::tool(
                'fa_research_state_summary',
                'Summarize the current tool-run research state for audit, follow-up and final answer grounding. Call this before the final answer when the research involved multiple tools or had failures.',
                [
                    'asset_type' => AIToolSchema::nullableEnum(['fund', 'stock', 'mixed'], 'Research asset type. Default fund when fund tools were used.'),
                    'focus' => AIToolSchema::nullableString('Short topic label, e.g. 红利型基金筛选.'),
                    'include_failures' => AIToolSchema::nullableBoolean('Whether to include failed tool calls. Default true.'),
                    'include_next_steps' => AIToolSchema::nullableBoolean('Whether to include recommended missing follow-up tools. Default true.'),
                ]
            ),
        ];
    }

    public static function researchTools(): array
    {
        $stockCode = self::stockCodeSchema();

        return [
            'fa_calculate_kline_indicators' => AIToolSchema::tool(
                'fa_calculate_kline_indicators',
                'Fetch K-line data and calculate MA, BOLL, MACD, RSI, KDJ, volatility, return, and recent high/low summary.',
                [
                    'code' => $stockCode,
                    'frequency' => AIToolSchema::nullableEnum(['1m', '5m', '15m', '30m', '60m', '1d', '1w', '1M'], 'K-line frequency. Default 1d.'),
                    'count' => AIToolSchema::nullableInteger('Number of bars. Default 120, max 500.', 30, 500),
                    'source' => AIToolSchema::nullableEnum(['auto', 'eastmoney', 'ashare', 'xueqiu'], 'Preferred data source.'),
                ]
            ),
            'fa_compare_candidates' => AIToolSchema::tool(
                'fa_compare_candidates',
                'Deterministically rank provided stock or fund candidates by numeric metrics already known to the model or returned by tools.',
                [
                    'asset_type' => AIToolSchema::nullableEnum(['stock', 'fund'], 'Candidate type.'),
                    'sort_metric' => AIToolSchema::nullableString('Metric key to sort by, such as percent, net_inflow, year_growth, score.'),
                    'order' => AIToolSchema::nullableEnum(['asc', 'desc'], 'Sort direction. Default desc.'),
                    'candidates' => [
                        'type' => 'array',
                        'description' => 'Candidate objects with code/name and metrics.',
                        'items' => AIToolSchema::strictObject([
                            'code' => ['type' => ['string', 'null']],
                            'name' => ['type' => ['string', 'null']],
                            'metrics' => [
                                'type' => ['array', 'null'],
                                'description' => 'Numeric metrics as key/value pairs, e.g. [{"key":"score","value":8.5}].',
                                'items' => AIToolSchema::strictObject([
                                    'key' => ['type' => 'string'],
                                    'value' => ['type' => ['number', 'string', 'null']],
                                ]),
                            ],
                        ]),
                    ],
                ]
            ),
        ];
    }

    private static function stockCodeSchema(): array
    {
        return ['type' => 'string', 'description' => 'Stock code such as 600519, sh600519, SZ000001, or 000001.XSHE.'];
    }

    private static function fundCodeSchema(): array
    {
        return ['type' => 'string', 'description' => 'Six digit fund code.'];
    }
}
