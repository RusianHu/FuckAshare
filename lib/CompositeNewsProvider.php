<?php
/**
 * CompositeNewsProvider — 新闻 Provider 能力路由。
 *
 * 默认顺序：站内搜索 → 个股 F10 公司资讯 → 7×24 快讯。
 * 能力裁剪不被误当作整条新闻链路故障；任一后续 Provider 成功即可返回。
 */

require_once __DIR__ . '/NewsDataProvider.php';
require_once __DIR__ . '/DataSourceResult.php';

class CompositeNewsProvider implements NewsDataProvider
{
    const SOURCE_NAME = 'eastmoney_news_composite';

    /** @var NewsDataProvider */
    private $primary;

    /** @var NewsDataProvider[] */
    private $fallbacks;

    public function __construct(NewsDataProvider $primary, NewsDataProvider $secondary, ?NewsDataProvider $tertiary = null)
    {
        $this->primary = $primary;
        $this->fallbacks = [$secondary];
        if ($tertiary !== null) $this->fallbacks[] = $tertiary;
    }

    public function sourceName(): string
    {
        return self::SOURCE_NAME;
    }

    public function search(string $keyword, int $limit = 20): DataSourceResult
    {
        return $this->searchMany([$keyword], $limit);
    }

    public function searchMany(array $keywords, int $limitPerKeyword = 20): DataSourceResult
    {
        $results = [];
        $primary = $this->primary->searchMany($keywords, $limitPerKeyword);
        $results[] = $primary;
        if ($primary->success && $primary->hasData()) {
            $primary->meta = array_merge($primary->meta, $this->routeMeta($results, $primary, 'primary'));
            return $primary;
        }

        $lastSuccess = $primary->success ? $primary : null;
        foreach ($this->fallbacks as $index => $provider) {
            $result = $provider->searchMany($keywords, $limitPerKeyword);
            $results[] = $result;
            if ($result->success) $lastSuccess = $result;
            if (!$result->success || !$result->hasData()) continue;

            $stage = $index === 0 ? 'secondary' : 'tertiary';
            return DataSourceResult::success(self::SOURCE_NAME, 'search_news', is_array($result->data) ? $result->data : [], array_merge(
                $result->meta,
                $this->routeMeta($results, $result, $stage)
            ));
        }

        // 至少一个 Provider 给出了有效空结果时保持 HTTP 200；
        // 这覆盖海外搜索桶裁剪以及“近期无关联快讯”，同时在 meta 中保留各源状态。
        if ($lastSuccess !== null) {
            return DataSourceResult::success(self::SOURCE_NAME, 'search_news', [], array_merge(
                $lastSuccess->meta,
                $this->routeMeta($results, $lastSuccess, 'empty'),
                ['partial' => count(array_filter($results, function (DataSourceResult $result): bool { return !$result->success; })) > 0]
            ));
        }

        return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'all_providers_failed', '全部新闻 Provider 均不可用', [
            'provider_route' => $this->routeMeta($results, null, 'failed'),
        ]);
    }

    /** @param DataSourceResult[] $results */
    private function routeMeta(array $results, ?DataSourceResult $active, string $stage): array
    {
        $primary = $results[0];
        $reason = null;
        if (!$primary->success) {
            $reason = $primary->errorCode ?: 'primary_failed';
        } elseif (!empty($primary->meta['capability_filtered'])) {
            $reason = (string)($primary->meta['capability_reason'] ?? 'primary_capability_filtered');
        } elseif (!$primary->hasData()) {
            $reason = 'primary_empty';
        }

        return [
            'active_provider' => $active ? $active->source : null,
            'provider_route_stage' => $stage,
            'provider_chain' => array_merge([$this->primary->sourceName()], array_map(function (NewsDataProvider $provider): string {
                return $provider->sourceName();
            }, $this->fallbacks)),
            'provider_route_reason' => $reason,
            'provider_statuses' => array_map([$this, 'providerStatus'], $results),
            'capability_filtered' => (bool)($primary->meta['capability_filtered'] ?? false),
            'content_exposed' => false,
        ];
    }

    private function providerStatus(DataSourceResult $result): array
    {
        return [
            'source' => $result->source,
            'success' => $result->success,
            'has_data' => $result->hasData(),
            'status' => $result->status,
            'error_code' => $result->errorCode,
            'capability_filtered' => (bool)($result->meta['capability_filtered'] ?? false),
            'count' => is_array($result->data) ? count($result->data) : 0,
        ];
    }
}
