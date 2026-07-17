<?php
/**
 * AnnouncementDataProvider — 可替换的股票公告数据源契约。
 */
interface AnnouncementDataProvider
{
    public function sourceName(): string;

    /**
     * @param array $query market/code/date_from/date_to/page/page_size
     */
    public function listAnnouncements(array $query): DataSourceResult;

    public function announcementDetail(string $announcementId): DataSourceResult;
}
