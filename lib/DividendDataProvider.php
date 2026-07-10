<?php
/**
 * DividendDataProvider — 可替换的股票分红/公司行动数据源契约。
 */

require_once __DIR__ . '/DataSourceResult.php';

interface DividendDataProvider
{
    public function sourceName(): string;

    /**
     * 获取指定登记日区间内的分红事件。
     */
    public function calendar(string $startDate, string $endDate): DataSourceResult;

    /**
     * 获取单只股票的完整分红历史。
     */
    public function detail(string $code): DataSourceResult;
}
