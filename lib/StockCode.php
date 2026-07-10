<?php
/**
 * StockCode — 股票代码归一化与多数据源格式转换
 *
 * 支持输入格式：
 *   600519 / sh600519 / SH600519 / 600519.XSHG / 000001.XSHE
 *
 * 数据源格式：
 *   东方财富: 1.600519 / 0.000001
 *   Ashare:   sh600519 / sz000001
 *   雪球:     SH600519 / SZ000001
 */

class StockCode
{
    /** @var string 原始输入 */
    public $raw;

    /** @var string 纯数字代码 (如 600519) */
    public $code;

    /** @var string 市场标识: SH / SZ / HK / UNKNOWN */
    public $market;

    /** @var string 归一化格式: sh600519 / sz000001 (小写前缀+数字) */
    public $normalized;

    /**
     * 解析股票代码
     *
     * @param string $input
     * @return self
     */
    public static function parse(string $input): self
    {
        $obj = new self();
        $obj->raw = $input;

        $upper = strtoupper($input);

        // 600519.XSHG / 000001.XSHE 格式
        if (preg_match('/^(\d{6})\.(XSHG|XSHE)$/i', $input, $m)) {
            $obj->code = $m[1];
            $obj->market = ($m[2] === 'XSHG') ? 'SH' : 'SZ';
        }
        // sh600519 / sz000001 格式
        elseif (preg_match('/^(sh|sz)(\d{6})$/i', $input, $m)) {
            $obj->code = $m[2];
            $obj->market = strtoupper($m[1]);
        }
        // SH600519 格式 (雪球风格)
        elseif (preg_match('/^(SH|SZ)(\d{6})$/', $upper, $m)) {
            $obj->code = $m[2];
            $obj->market = $m[1];
        }
        // 纯数字 600519 / 000001
        elseif (preg_match('/^(\d{6})$/', $input, $m)) {
            $obj->code = $m[1];
            $obj->market = self::inferMarket($m[1]);
        }
        // 港股 01810 等 5 位
        elseif (preg_match('/^(\d{5})$/', $input, $m)) {
            $obj->code = $m[1];
            $obj->market = 'HK';
        }
        else {
            $obj->code = $input;
            $obj->market = 'UNKNOWN';
        }

        $obj->normalized = strtolower($obj->market) . $obj->code;

        return $obj;
    }

    /**
     * 根据代码数字推断市场
     */
    private static function inferMarket(string $code): string
    {
        if (preg_match('/^68/', $code)) return 'SH';
        if (preg_match('/^(?:6|900)/', $code)) return 'SH';
        if (preg_match('/^(?:0|3|200)/', $code)) return 'SZ';
        if (preg_match('/^(?:4|8|92)/', $code)) return 'BJ';
        return 'UNKNOWN';
    }

    // ── 数据源格式转换 ──

    /** 东方财富 secid: 1.600519 / 0.000001 */
    public function toEastmoneySecid(): string
    {
        $map = [
            'SH' => '1',
            'SZ' => '0',
            'BJ' => '0',
            'HK' => '116',
        ];
        return ($map[$this->market] ?? '0') . '.' . $this->code;
    }

    /** Ashare 格式: sh600519 / sz000001 */
    public function toAshare(): string
    {
        return strtolower($this->market) . $this->code;
    }

    /** 雪球格式: SH600519 / SZ000001 */
    public function toXueqiu(): string
    {
        return strtoupper($this->market) . $this->code;
    }

    /** 前端展示格式: sh600519 (兼容现有前端) */
    public function toDisplay(): string
    {
        return $this->normalized;
    }

    /** 是否为 A 股 */
    public function isAStock(): bool
    {
        if (!in_array($this->market, ['SH', 'SZ', 'BJ'], true)) {
            return false;
        }

        // 沪市 900xxx、深市 200xxx 为 B 股，不应混入 A 股事件扫描。
        if (($this->market === 'SH' && preg_match('/^900/', $this->code))
            || ($this->market === 'SZ' && preg_match('/^200/', $this->code))) {
            return false;
        }

        return true;
    }

    /** 是否有效 */
    public function isValid(): bool
    {
        return $this->market !== 'UNKNOWN' && preg_match('/^\d{5,6}$/', $this->code);
    }
}
