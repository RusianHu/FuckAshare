<?php

class StrategyRegistry
{
    /** @var array<string,array> */
    private $strategies;

    public function __construct()
    {
        $this->strategies = $this->buildStrategies();
    }

    public function all(): array
    {
        return $this->strategies;
    }

    public function get(string $id): ?array
    {
        return $this->strategies[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->strategies[$id]);
    }

    public function ids(): array
    {
        return array_keys($this->strategies);
    }

    public function normalizeIds(array $ids): array
    {
        $known = $this->ids();
        if (!$ids) return $known;

        $set = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if (isset($this->strategies[$id])) $set[$id] = true;
        }
        return $set ? array_keys($set) : $known;
    }

    public function listStrategies(): array
    {
        return array_values(array_map(function ($s) {
            return [
                'id' => $s['id'],
                'name' => $s['name'],
                'description' => $s['description'],
                'tags' => $s['tags'],
                'source' => $s['source'] ?? 'builtin',
                'source_ref' => $s['source_ref'] ?? '',
                'risk_note' => $s['risk_note'] ?? '',
                'watch_only' => !empty($s['watch_only']),
                'asset_type' => $s['asset_type'] ?? 'stock',
                'version' => $s['version'] ?? '1.0',
                'default_pool' => !empty($s['default_pool']),
                'params' => $s['params'],
                'scoring' => $s['scoring'],
                'limit' => $s['limit'],
                'stop_loss' => $s['stop_loss'],
                'max_hold_days' => $s['max_hold_days'],
            ];
        }, $this->strategies));
    }

    public function defaultPool(): array
    {
        $ids = [];
        foreach ($this->strategies as $id => $strategy) {
            if (!empty($strategy['default_pool'])) $ids[] = $id;
        }
        return $ids;
    }

    public function strategyVersions(array $ids): array
    {
        $versions = [];
        foreach ($ids as $id) {
            if (isset($this->strategies[$id])) {
                $versions[$id] = $this->strategies[$id]['version'] ?? '1.0';
            }
        }
        return $versions;
    }

    private function buildStrategies(): array
    {
        $base = ['price_min' => 3, 'price_max' => 300, 'market_cap_min' => 10e8, 'amount_min' => 0.2e8, 'exclude_st' => true];
        $make = function ($id, $name, $desc, $tags, $params, $scoring, $limit = 100, $basic = [], $stop = -0.06, $hold = 15, $extra = []) use ($base) {
            $defaults = [];
            foreach ($params as $p) $defaults[$p['id']] = $p['default'];
            return array_merge([
                'id' => $id,
                'name' => $name,
                'description' => $desc,
                'tags' => $tags,
                'params' => $params,
                'defaults' => $defaults,
                'scoring' => $scoring,
                'limit' => $limit,
                'basic_filter' => array_merge($base, $basic),
                'descending' => true,
                'stop_loss' => $stop,
                'max_hold_days' => $hold,
                'source' => 'builtin',
                'source_ref' => '',
                'risk_note' => '策略池结果仅用于研究候选，不构成投资建议。',
                'watch_only' => false,
                'asset_type' => 'stock',
                'version' => '1.0',
                'needs_history' => true,
                'default_pool' => false,
            ], $extra);
        };
        $p = function ($id, $label, $default, $min, $max, $step, $type = 'float') {
            return compact('id', 'label', 'type', 'default', 'min', 'max', 'step');
        };

        $items = [
            $make('trend_breakout', '趋势突破', 'MA60 上方 + 60 日新高 + 量能 >= 2 倍均量', ['趋势','突破','放量'], [$p('vol_ratio_min','最低量比',2.0,0.5,10,0.1)], ['momentum_60d'=>0.4,'vol_ratio_5d'=>0.3,'change_pct'=>0.3], 100, ['price_min'=>5,'price_max'=>200,'market_cap_min'=>20e8,'amount_min'=>1e8], -0.08, 20, ['default_pool' => true]),
            $make('ma_golden_cross', 'MA 金叉', 'MA5 上穿 MA20 当日触发，量能配合', ['均线','金叉'], [$p('vol_ratio_min','最低量比',1.2,0.5,5,0.1)], ['momentum_20d'=>0.5,'vol_ratio_5d'=>0.3,'change_pct'=>0.2]),
            $make('macd_golden', 'MACD 金叉放量', 'MACD 金叉当日 + 量能放大', ['MACD','金叉','放量'], [$p('vol_ratio_min','最低量比',1.5,0.5,5,0.1)], ['momentum_60d'=>0.4,'vol_ratio_5d'=>0.3,'change_pct'=>0.3], 100, [], -0.07, 20, ['default_pool' => true]),
            $make('volume_price_surge', '量价齐升', '突破 MA20 + 放量 + 收阳', ['量价','突破'], [$p('vol_ratio_min','最低量比',2.0,0.5,10,0.1)], ['vol_ratio_5d'=>0.4,'change_pct'=>0.3,'momentum_20d'=>0.3], 100, [], -0.06, 15, ['default_pool' => true]),
            $make('low_volatility_leader', '低波动龙头', '20 日动量为正 + 年化波动 < 30% + MA20 上方', ['低波动','龙头'], [$p('vol_max','最大年化波动',0.30,0.05,1,0.01)], ['momentum_60d'=>0.4,'momentum_20d'=>0.3,'turnover_rate'=>0.3], 100, [], -0.05, 30),
            $make('broken_board_recovery', '断板反包', '连板 >= 2 后断板 1-2 天，出现放量反包信号', ['涨停','反包'], [$p('vol_ratio_min','最低量比',1.5,0.5,5,0.1), $p('change_pct_min','最低涨幅',0.03,0.01,0.10,0.01)], ['change_pct'=>0.4,'vol_ratio_5d'=>0.3,'momentum_5d'=>0.3], 100, [], -0.06, 10),
            $make('oversold_bounce', '超跌反弹', 'RSI14 < 30 超卖区 + 当日收阳 + 放量', ['超跌','反弹','RSI'], [$p('rsi_max','RSI上限',30,10,50,1), $p('vol_ratio_min','最低量比',1.2,0.5,5,0.1)], ['change_pct'=>0.3,'vol_ratio_5d'=>0.3,'momentum_5d'=>0.2,'rsi_14'=>0.2], 100, [], -0.05, 15),
            $make('boll_breakout', '布林突破', '突破布林上轨 + 放量，强势加速信号', ['布林','突破'], [$p('vol_ratio_min','最低量比',1.5,0.5,5,0.1)], ['vol_ratio_5d'=>0.4,'change_pct'=>0.3,'momentum_20d'=>0.3]),
            $make('bullish_alignment', '均线多头', 'MA5 > MA10 > MA20 > MA60 多头排列 + 短期动量为正', ['均线','多头'], [], ['momentum_60d'=>0.4,'momentum_20d'=>0.3,'turnover_rate'=>0.3], 100, [], -0.06, 20, ['default_pool' => true]),
            $make('consecutive_limit_ups', '连板股', '当日涨停且连续涨停 >= 2 天，强势追涨', ['涨停','连板'], [$p('min_boards','最少连板数',2,1,20,1,'int')], ['consecutive_limit_ups'=>0.5,'change_pct'=>0.3,'amount'=>0.2], 100, [], -0.05, 5),
            $make('pullback_to_support', '缩量回踩', '回踩 MA20 附近 + 缩量 + 中期趋势向上', ['回踩','支撑'], [$p('ma_proximity','均线偏离度',0.02,0.01,0.05,0.005), $p('vol_ratio_max','最大量比',0.8,0.2,1.5,0.1)], ['momentum_60d'=>0.4,'momentum_20d'=>0.3,'turnover_rate'=>0.3], 100, [], -0.05, 20),
            $make('n_day_low_reversal', '新低反转', '触及 60 日新低后当日收阳放量，反转信号', ['反转','新低'], [$p('vol_ratio_min','最低量比',1.5,0.5,5,0.1)], ['change_pct'=>0.4,'vol_ratio_5d'=>0.3,'momentum_5d'=>0.3]),
            $make('high_turnover_surge', '高换手拉升', '换手率 > 5% 且涨幅 > 3%，资金活跃', ['换手率','放量','资金'], [$p('min_turnover','最低换手率%',5,1,20,0.5), $p('min_change','最低涨幅%',3,1,10,0.5)], ['turnover_rate'=>0.4,'change_pct'=>0.3,'momentum_5d'=>0.3], 50, [], -0.05, 10, ['needs_history' => false, 'default_pool' => true]),
            $make('limit_up_momentum', '连板接力', '连板股 + 今日涨幅 > 5%，连板接力追踪', ['涨停','连板','接力'], [$p('min_change','最低涨幅%',5,2,15,0.5), $p('min_boards','最少连板',1,1,10,1,'int')], ['consecutive_limit_ups'=>0.4,'change_pct'=>0.3,'amount'=>0.3], 50, [], -0.05, 5, ['default_pool' => true]),
            $make('near_limit_up', '逼近涨停', '涨幅已接近涨停价，追踪强势临界状态', ['涨停','追涨'], [$p('min_change','最低涨幅%',7,3,15,1), $p('limit_gap','距涨停空间%',3,1,10,0.5)], ['change_pct'=>0.5,'amount'=>0.3,'momentum_5d'=>0.2], 50, [], -0.05, 5, ['needs_history' => false, 'default_pool' => true]),
            $make('strong_open', '强势高开', '高开 > 3% 且收盘高于开盘价，集合竞价强势', ['高开','强势'], [$p('min_open_gap','最低高开%',3,1,10,0.5), $p('min_change','最低涨幅%',3,1,10,0.5)], ['change_pct'=>0.4,'amplitude'=>0.2,'amount'=>0.4], 50, [], -0.05, 10, ['needs_history' => false, 'default_pool' => true]),

            $make('donchian_turtle_breakout', 'Donchian 海龟突破', '突破前 55 日高点 + MA60 上方 + ATR/量能确认', ['Donchian','海龟','ATR'], [$p('donchian_period','突破周期',55,20,120,5,'int'), $p('atr_period','ATR周期',14,5,30,1,'int'), $p('min_atr_pct','最低ATR%',2.0,0.5,12,0.1), $p('vol_ratio_min','最低量比',1.2,0.5,6,0.1)], ['donchian_position_55'=>0.35,'momentum_60d'=>0.25,'vol_ratio_5d'=>0.2,'atr_pct'=>0.2], 80, ['amount_min'=>1e8], -0.08, 25, [
                'source_ref' => 'Turtle/Donchian breakout; Backtrader/QuantConnect-style trend following references',
                'risk_note' => '突破策略在震荡市容易假突破，需结合仓位和止损。',
                'version' => '1.1',
                'default_pool' => true,
            ]),
            $make('dual_thrust_range_breakout', 'Dual Thrust 日线观察', '按近 N 日区间估算上轨，盘中突破后列入观察', ['Dual Thrust','区间突破','观察'], [$p('range_period','区间周期',4,2,20,1,'int'), $p('k_up','上轨系数',0.5,0.1,1.5,0.05), $p('min_change','最低涨幅%',2,0,15,0.5)], ['dual_thrust_strength'=>0.45,'change_pct'=>0.25,'vol_ratio_5d'=>0.3], 60, ['amount_min'=>1e8], -0.06, 5, [
                'source_ref' => 'Dual Thrust strategy; je-suis-tm/quant-trading',
                'risk_note' => '当前为日线观察版，不代表真实盘中触发价或成交信号。',
                'watch_only' => true,
                'version' => '1.0',
            ]),
            $make('short_term_reversal', '短期反转', '5 日明显回撤 + RSI偏低 + 当日收阳，寻找超跌修复候选', ['短期反转','RSI','修复'], [$p('max_return_5d','5日最大收益',-0.08,-0.3,0,0.01), $p('rsi_max','RSI上限',38,10,55,1), $p('vol_ratio_min','最低量比',0.8,0.2,5,0.1)], ['reversal_score'=>0.45,'vol_ratio_5d'=>0.25,'amount'=>0.3], 80, ['amount_min'=>0.8e8], -0.05, 10, [
                'source_ref' => 'QuantConnect Strategy Library short-term reversal ideas',
                'risk_note' => '反转策略可能接到下跌中继，需过滤基本面和流动性风险。',
                'version' => '1.0',
                'default_pool' => true,
            ]),
            $make('multi_factor_score', '轻量多因子评分', '动量、低波、估值、流动性、市值可得因子的横截面综合评分', ['多因子','动量','估值'], [$p('min_factor_score','最低因子分',65,0,100,1), $p('max_pe_ttm','最大PE',120,5,300,5), $p('max_pb','最大PB',20,0.5,80,0.5)], ['factor_score'=>1.0], 100, ['amount_min'=>1e8], -0.07, 20, [
                'source_ref' => 'Microsoft Qlib style modular factor pipeline, implemented as lightweight available-data scoring',
                'risk_note' => '缺少 ROE/成长性等完整财务因子时，只能视为轻量横截面评分。',
                'version' => '1.0',
                'default_pool' => true,
            ]),
            $make('formula_ohlcv_alpha_subset', 'OHLCV 公式 Alpha 子集', '从 101 Formulaic Alphas 思路中选取本项目可验证的 OHLCV 子集', ['公式Alpha','OHLCV','实验'], [$p('min_alpha_score','最低Alpha分',68,0,100,1), $p('min_amount_yuan','最低成交额',1e8,0.2e8,50e8,0.1e8)], ['formula_alpha_score'=>1.0], 80, ['amount_min'=>0.8e8], -0.06, 10, [
                'source_ref' => 'arXiv 1601.00991 101 Formulaic Alphas, reduced to OHLCV-only subset',
                'risk_note' => '公式 Alpha 子集未经完整回测和行业中性化，默认不加入自动策略池。',
                'watch_only' => true,
                'version' => '1.0',
            ]),
            $make('sector_rotation_momentum', '行业资金轮动', '板块当日/5日/10日主力净流入与涨幅共振', ['行业轮动','板块资金','动量'], [$p('min_net_inflow_yuan','最低当日净流入',1e8,0,50e8,0.1e8), $p('min_change_pct','最低涨幅%',0.5,-10,20,0.1)], ['sector_score'=>1.0], 50, [], -0.0, 5, [
                'asset_type' => 'sector',
                'source_ref' => 'QuantConnect asset-class/sector momentum ideas adapted to Eastmoney sector flow',
                'risk_note' => '板块轮动输出为板块观察清单，不是个股买卖信号。',
                'needs_history' => false,
                'version' => '1.0',
                'default_pool' => true,
            ]),
            $make('strategy_validation_healthcheck', '策略链路健康检查', '检查候选池、K线、板块资金和策略依赖覆盖率', ['诊断','健康检查'], [], ['score'=>1.0], 20, [], 0, 0, [
                'asset_type' => 'diagnostic',
                'source_ref' => 'Internal validation inspired by Alphalens/Backtrader/LEAN verification discipline',
                'risk_note' => '该项只用于链路诊断，不参与选股。',
                'needs_history' => false,
                'watch_only' => true,
                'version' => '1.0',
            ]),
        ];

        $out = [];
        foreach ($items as $item) $out[$item['id']] = $item;
        return $out;
    }
}
