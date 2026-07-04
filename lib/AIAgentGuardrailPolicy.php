<?php
/**
 * AIAgentGuardrailPolicy — financial-answer safety and output requirements.
 */

class AIAgentGuardrailPolicy
{
    public function finalSystemMessage(): array
    {
        return [
            'role' => 'system',
            'content' => implode("\n", [
                '最终回答必须遵守金融研究护栏：',
                '1. 禁止承诺收益、保证上涨、保证回本、稳赚不赔等确定性表述。',
                '2. 禁止给出“必须买入/卖出/满仓/梭哈”等个性化交易指令。',
                '3. 必须区分工具返回的事实、基于事实的推断、不确定性和继续验证条件。',
                '4. 必须说明数据时效和工具失败项；不要编造实时数据、新闻或财务数据。',
                '5. 禁止输出 <function=...>、<parameter=...> 等伪工具调用标签。',
                '6. 结尾必须包含“内容仅供研究参考，不构成投资建议。”',
            ]),
        ];
    }

    public function reviewFinalText(string $text): array
    {
        if ($this->looksLikeOperationalMetaAnswer($text) || !$this->looksLikeFinancialResearch($text)) {
            return [
                'ok' => true,
                'violations' => [],
                'append_text' => '',
            ];
        }

        $violations = [];
        foreach ($this->forbiddenReturnPatterns() as $pattern) {
            if (preg_match($pattern, $text)) {
                $violations[] = 'promised_return';
                break;
            }
        }
        foreach ($this->forbiddenTradeCommandPatterns() as $pattern) {
            if (preg_match($pattern, $text)) {
                $violations[] = 'deterministic_trade_command';
                break;
            }
        }
        if (!$this->mentionsFactInferenceUncertainty($text)) {
            $violations[] = 'missing_fact_inference_uncertainty';
        }
        if (strpos($text, '不构成投资建议') === false) {
            $violations[] = 'missing_risk_disclaimer';
        }

        return [
            'ok' => empty($violations),
            'violations' => array_values(array_unique($violations)),
            'append_text' => $this->correctiveSuffix($violations),
        ];
    }

    public function toolAccessIsReadOnly(array $toolNames): bool
    {
        foreach ($toolNames as $name) {
            if (!is_string($name) || strpos($name, 'fa_') !== 0) {
                return false;
            }
        }
        return true;
    }

    private function looksLikeOperationalMetaAnswer(string $text): bool
    {
        return (bool)preg_match('/(接口|服务|系统|AI顾问|工具).{0,24}(正常|运行|可用|可调用|已启动|健康检查)|正常.{0,16}(运行|使用|服务|调用)|只读工具.{0,16}(正常|可用|调用)/u', $text);
    }

    private function looksLikeFinancialResearch(string $text): bool
    {
        return (bool)preg_match(
            '/(股票|A股|基金|ETF|债券|转债|指数|行情|资金流|主力|净值|估值|排行|涨幅|跌幅|回撤|收益|风险|买入|卖出|持有|仓位|投资|交易|申购|赎回|板块|行业|K线|MACD|RSI|PE|PB)/iu',
            $text
        );
    }

    private function forbiddenReturnPatterns(): array
    {
        return [
            '/保证(?:收益|上涨|盈利|回本)/u',
            '/稳赚(?:不赔)?/u',
            '/必(?:涨|赚|盈利|回本)/u',
            '/无风险/u',
            '/一定(?:上涨|赚钱|盈利|回本)/u',
        ];
    }

    private function forbiddenTradeCommandPatterns(): array
    {
        return [
            '/(?:必须|务必|直接|马上|立刻)(?:买入|卖出|建仓|清仓|满仓|加仓)/u',
            '/(?:满仓|梭哈|重仓)(?:买入|介入|持有)?/u',
            '/(?:明天|下周|短期)必(?:涨|跌|涨停|跌停)/u',
            '/确定性(?:买点|卖点|机会)/u',
        ];
    }

    private function mentionsFactInferenceUncertainty(string $text): bool
    {
        $hasFact = preg_match('/(事实|数据|工具|行情|资金流|指标|净值|排行)/u', $text);
        $hasInference = preg_match('/(推断|判断|倾向|可能|显示|表明|意味着)/u', $text);
        $hasUncertainty = preg_match('/(不确定|风险|需(?:要)?验证|缺失|不足|波动|失败)/u', $text);
        return (bool)($hasFact && $hasInference && $hasUncertainty);
    }

    private function correctiveSuffix(array $violations): string
    {
        $violations = array_values(array_unique($violations));
        if (empty($violations)) {
            return '';
        }

        $lines = [];
        if (in_array('promised_return', $violations, true) || in_array('deterministic_trade_command', $violations, true)) {
            $lines[] = '护栏修正：上文如出现确定收益、必涨必跌或直接买卖指令，应视为无效表述；本系统只提供基于公开行情/资金/指标数据的研究参考。';
        }
        if (in_array('missing_fact_inference_uncertainty', $violations, true)) {
            $lines[] = '补充说明：请将工具返回的行情、资金流、技术指标、基金净值/排行视为事实层；由这些数据得出的强弱、趋势和候选排序属于推断层；数据延迟、工具失败、市场波动和事件冲击属于不确定性。';
        }
        if (in_array('missing_risk_disclaimer', $violations, true)) {
            $lines[] = '内容仅供研究参考，不构成投资建议。';
        }

        return "\n\n" . implode("\n", $lines);
    }
}
