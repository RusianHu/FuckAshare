<?php
/**
 * AIAgentProfile — runtime persona and policy hints without hiding tools.
 */

class AIAgentProfile
{
    /** @var string */
    private $name;

    /** @var string */
    private $description;

    /** @var array */
    private $optionHints;

    private function __construct(string $name, string $description, array $optionHints = [])
    {
        $this->name = $name;
        $this->description = $description;
        $this->optionHints = $optionHints;
    }

    public static function advisor(): self
    {
        return new self('advisor', '通用金融研究顾问', [
            'max_deep_dive_candidates' => 10,
        ]);
    }

    public static function marketScanner(): self
    {
        return new self('market_scanner', '市场扫描和候选排序', [
            'max_deep_dive_candidates' => 10,
        ]);
    }

    public static function fundResearcher(): self
    {
        return new self('fund_researcher', '基金研究', [
            'max_deep_dive_candidates' => 6,
        ]);
    }

    public static function riskReviewer(): self
    {
        return new self('risk_reviewer', '风险审查', [
            'max_deep_dive_candidates' => 8,
        ]);
    }

    public static function resolve(array $messages, array $options = []): self
    {
        $forced = (string)($options['agent_profile'] ?? '');
        if ($forced !== '') {
            return self::byName($forced);
        }

        $latestUser = self::latestUserContent($messages);
        if (preg_match('/(基金|净值|估值|基金经理|基金公司|申购|赎回|同类排行|同类排名|基金排行|基金排名|基金信息|基金资料|开放式基金|ETF|etf|QDII|qdii)/u', $latestUser)) {
            return self::fundResearcher();
        }
        if (preg_match('/(风险|回撤|不确定|审查|复盘|踩雷|止损|波动)/u', $latestUser)) {
            return self::riskReviewer();
        }
        if (preg_match('/(资金流入|净流入|主力流入|资金|资金榜|热股|热门股|候选|选股|筛选|前十|前10|top\s*10|排名|排行|涨(?:得|的)?(?:最)?多|涨幅|领涨|上涨(?:最)?多|涨幅榜)/iu', $latestUser)) {
            return self::marketScanner();
        }
        return self::advisor();
    }

    public static function byName(string $name): self
    {
        switch ($name) {
            case 'market_scanner':
                return self::marketScanner();
            case 'fund_researcher':
                return self::fundResearcher();
            case 'risk_reviewer':
                return self::riskReviewer();
            case 'advisor':
            default:
                return self::advisor();
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function toolsAreFullyAvailable(): bool
    {
        return true;
    }

    public function optionHints(): array
    {
        return $this->optionHints;
    }

    public function systemPromptSuffix(): string
    {
        $lines = [
            "当前智能体档案：{$this->name}（{$this->description}）。",
            '无论使用哪个档案，服务端提供的全部只读研究工具都保持可用；档案只影响分析重点、深挖偏好和输出结构。',
        ];

        if ($this->name === 'market_scanner') {
            $lines[] = '市场扫描任务必须先形成候选池，再结合行情、技术指标、资金流、板块/热度交叉验证，不要只复述榜单。';
        } elseif ($this->name === 'fund_researcher') {
            $lines[] = '基金研究任务必须关注净值/估值、历史表现、同类排行、基金经理和回撤风险；如用户要求跨资产、成分线索、相关股票或市场背景，也可以调用股票、板块和热度工具补充判断。';
        } elseif ($this->name === 'risk_reviewer') {
            $lines[] = '风险审查任务必须优先列出事实、风险触发点、反证条件和需要继续验证的数据缺口。';
        } else {
            $lines[] = '通用研究任务必须先获取事实，再给出分层结论和风险提示。';
        }

        return implode("\n", $lines);
    }

    public function metadata(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'tools_fully_available' => true,
            'option_hints' => $this->optionHints,
        ];
    }

    private static function latestUserContent(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return (string)($messages[$i]['content'] ?? '');
            }
        }
        return '';
    }
}
