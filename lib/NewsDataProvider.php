<?php
/**
 * NewsDataProvider — 可替换的新闻数据源契约。
 *
 * Provider 只负责返回允许公开展示的最小字段：标题、来源、时间、链接。
 */
interface NewsDataProvider
{
    public function sourceName(): string;

    public function search(string $keyword, int $limit = 20): DataSourceResult;

    /**
     * @param string[] $keywords
     */
    public function searchMany(array $keywords, int $limitPerKeyword = 20): DataSourceResult;
}
