<?php
/**
 * AnnouncementClassifier — 确定性公告事件分类与重要性规则。
 *
 * 不输出利好/利空；importance 只用于降低程序性公告噪声。
 */
class AnnouncementClassifier
{
    const VERSION = 'v1';

    /** @var string[] */
    const EVENT_TYPES = [
        'performance',
        'capital_operation',
        'ownership',
        'operation',
        'dividend',
        'governance',
        'risk_regulatory',
        'other',
    ];

    public function classify(string $title, string $categoryRaw = ''): array
    {
        $text = trim($categoryRaw . ' ' . $title);
        $eventType = $this->eventType($text);
        [$importance, $reasons] = $this->importance($text, $eventType);

        return [
            'event_type' => $eventType,
            'importance' => $importance,
            'importance_reasons' => $reasons,
            'classification_version' => self::VERSION,
        ];
    }

    private function eventType(string $text): string
    {
        // 风险与监管优先，避免“董事会关于立案调查的公告”被治理类截获。
        if ($this->matches($text, '/立案|调查通知|行政处罚|监管措施|纪律处分|风险警示|退市|终止上市|异常波动|诉讼|仲裁|破产|重整|问询函|监管工作函|关注函|处罚决定/u')) {
            return 'risk_regulatory';
        }
        if ($this->matches($text, '/年度报告|半年度报告|季度报告|季报|业绩预告|业绩快报|盈利预测|业绩说明|财务报告|审计报告/u')) {
            return 'performance';
        }
        if ($this->matches($text, '/利润分配|权益分派|权益分配|现金分红|派发现金|分红派息|股息红利/u')) {
            return 'dividend';
        }
        if ($this->matches($text, '/股份回购|回购股份|增持|减持|股份质押|股份冻结|解除质押|权益变动|股权激励|限制性股票|股票期权|解除限售|限售股|实际控制人变更/u')) {
            return 'ownership';
        }
        if ($this->matches($text, '/重大资产重组|并购重组|发行股份|定向增发|非公开发行|公开发行|增发|配股|可转换公司债|可转债|募集资金|募投|收购|出售资产|资产置换|重大投资|对外投资|股权转让/u')) {
            return 'capital_operation';
        }
        if ($this->matches($text, '/重大合同|合同公告|签订.*合同|中标|项目进展|项目投资|取得许可|获得许可|产品获批|新产品|经营情况|产销数据|订单/u')) {
            return 'operation';
        }
        if ($this->matches($text, '/董事会|监事会|股东大会|股东会|高级管理人员|高管|董事.*任职|公司章程|议事规则|管理制度|管理办法/u')) {
            return 'governance';
        }
        return 'other';
    }

    private function importance(string $text, string $eventType): array
    {
        $routinePatterns = [
            '法律意见书' => '/法律意见书/u',
            '保荐核查意见' => '/保荐.*(?:核查|专项).*意见|核查意见/u',
            '独立专项意见' => '/独立董事.*意见|专项说明/u',
            '内部制度文件' => '/管理办法|管理制度|工作制度|议事规则|实施细则/u',
            '章程修订文件' => '/公司章程.*(?:修订|修正)|章程修正案/u',
        ];
        foreach ($routinePatterns as $reason => $pattern) {
            if ($this->matches($text, $pattern)) return ['routine', [$reason]];
        }

        $importantPatterns = [
            '监管或重大风险' => '/立案|行政处罚|风险警示|退市|终止上市|重大诉讼|重大仲裁|破产|重整|异常波动|问询函|监管工作函/u',
            '业绩披露' => '/业绩预告|业绩快报|年度报告|半年度报告|季度报告|盈利预测/u',
            '重大资本运作' => '/重大资产重组|并购重组|发行股份|定向增发|非公开发行|收购|出售资产|资产置换|募投.*变更|增发终止/u',
            '重要股权事项' => '/回购股份|股份回购|增持|减持|股份质押|股份冻结|权益变动|股权激励|实际控制人变更/u',
            '重大经营事项' => '/重大事项|重大合同|中标|产品获批|取得许可|重大投资/u',
            '利润分配事项' => '/利润分配|权益分派|现金分红|分红派息/u',
        ];
        $reasons = [];
        foreach ($importantPatterns as $reason => $pattern) {
            if ($this->matches($text, $pattern)) $reasons[] = $reason;
        }
        if (!empty($reasons)) return ['important', array_values(array_unique($reasons))];

        if (in_array($eventType, ['performance', 'capital_operation', 'ownership', 'operation', 'dividend', 'risk_regulatory'], true)) {
            return ['important', ['事件类型规则:' . $eventType]];
        }
        return ['normal', ['默认规则']];
    }

    private function matches(string $text, string $pattern): bool
    {
        return $text !== '' && preg_match($pattern, $text) === 1;
    }
}
