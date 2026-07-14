<?php
/**
 * FundDividendDataProvider - 可替换的基金分红/收益分配数据源契约。
 *
 * 与股票分红 DividendDataProvider 平行，但面向全市场公募基金事件源：
 *   - calendar(): 全市场基金分红事件（按权益登记日区间）。
 *   - fundTypeMap(): 基金代码 -> 归一化类型映射，用于 fund_category 筛选。
 *
 * 单基金分红历史/公告/联接基金目标 ETF 等详情证据由 FundService 复用既有接口提供，
 * 不在 Provider 契约内，避免重复实现。
 */

require_once __DIR__ . '/DataSourceResult.php';

interface FundDividendDataProvider
{
    public function sourceName(): string;

    /**
     * 获取指定权益登记日区间内的全市场基金分红事件。
     *
     * 返回 data 为标准化事件数组，每条至少包含：
     * code/name/record_date/ex_date/pay_date/cash_per_unit/source_flag/year/source。
     * 已按 code+record_date+ex_date+pay_date+cash_per_unit 去重。
     */
    public function calendar(string $startDate, string $endDate): DataSourceResult;

    /**
     * 获取基金代码 -> 归一化类型映射。
     *
     * 返回 data 为 [code => category] 关联数组，
     * category 取值：stock|index|mixed|bond|money|fof|qdii|reit|other。
     */
    public function fundTypeMap(): DataSourceResult;
}
