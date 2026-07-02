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
                'Get fund dividend/distribution records parsed from historical NAV dividend cells.',
                [
                    'code' => $fundCode,
                    'page' => AIToolSchema::nullableInteger('Page number.', 1, 200),
                    'page_size' => AIToolSchema::nullableInteger('Page size.', 1, 100),
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
