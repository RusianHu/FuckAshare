<?php
/**
 * FundService — 基金数据统一服务层
 * 封装基金估值、基金详情、基金搜索，复用 HttpClient 与文件缓存
 */

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/DataSourceResult.php';

class FundService
{
    const SOURCE_NAME = 'eastmoney_fund';

    /** @var HttpClient */
    private $http;

    /** @var string 缓存目录 */
    private $cacheDir;

    /** @var array 缓存 TTL 配置 (秒) */
    const CACHE_TTL = [
        'estimate'    => 10,     // 基金实时估值：短缓存
        'batch_estimate' => 10,
        'info'        => 300,    // 基金详情：5 分钟
        'search'      => 600,   // 基金搜索：10 分钟
    ];

    public function __construct()
    {
        $this->http = new HttpClient([
            'timeout' => 10,
            'headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer: https://fund.eastmoney.com/',
            ],
        ]);
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_cache';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0700, true);
        }
    }

    /**
     * 单只基金实时估值
     */
    public function estimate(string $code): DataSourceResult
    {
        return $this->useCache('estimate', $code, function() use ($code) {
            $url = "https://fundgz.1234567.com.cn/js/{$code}.js?rt=" . time();
            $resp = $this->http->get($url, [
                'Referer: https://fund.eastmoney.com/',
            ]);

            if ($resp['error'] || $resp['http_code'] !== 200) {
                return DataSourceResult::error(self::SOURCE_NAME, 'estimate', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
            }

            if (preg_match('/jsonpgz\((.+)\);?/s', $resp['body'], $matches)) {
                $data = json_decode($matches[1], true);
                if ($data) {
                    $result = [
                        'fundcode' => $data['fundcode'] ?? '',
                        'name'     => $data['name'] ?? '',
                        'jzrq'     => $data['jzrq'] ?? '',
                        'dwjz'     => $data['dwjz'] ?? '',
                        'gsz'      => $data['gsz'] ?? '',
                        'gszzl'    => $data['gszzl'] ?? '',
                        'gztime'   => $data['gztime'] ?? '',
                    ];
                    return DataSourceResult::success(self::SOURCE_NAME, 'estimate', $result);
                }
            }

            return DataSourceResult::error(self::SOURCE_NAME, 'estimate', 'parse_error', '解析基金估值数据失败，可能非交易时间或基金代码不存在');
        });
    }

    /**
     * 批量基金实时估值
     *
     * @param string[] $codes 基金代码数组
     * @return DataSourceResult
     */
    public function batchEstimate(array $codes): DataSourceResult
    {
        $validCodes = array_filter($codes, function($c) {
            return preg_match('/^\d{6}$/', $c);
        });

        if (empty($validCodes)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'batch_estimate', 'invalid_code', '没有有效的基金代码');
        }

        // 先检查缓存
        $results = [];
        $missCodes = [];
        foreach ($validCodes as $code) {
            $cached = $this->getCache('estimate', $code);
            if ($cached !== null && $cached->hasData()) {
                $results[$code] = $cached->data;
            } else {
                $missCodes[] = $code;
            }
        }

        // 并发请求未命中缓存的基金（使用 curl_multi）
        if (!empty($missCodes)) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($missCodes as $code) {
                $url = "https://fundgz.1234567.com.cn/js/{$code}.js?rt=" . time();
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Referer: https://fund.eastmoney.com/',
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$code] = $ch;
            }

            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 1);
                }
            } while ($active && $status === CURLM_OK);

            foreach ($handles as $code => $ch) {
                $body = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($httpCode === 200 && preg_match('/jsonpgz\((.+)\);?/s', $body, $matches)) {
                    $data = json_decode($matches[1], true);
                    if ($data) {
                        $item = [
                            'fundcode' => $data['fundcode'] ?? $code,
                            'name'     => $data['name'] ?? '',
                            'jzrq'     => $data['jzrq'] ?? '',
                            'dwjz'     => $data['dwjz'] ?? '',
                            'gsz'      => $data['gsz'] ?? '',
                            'gszzl'    => $data['gszzl'] ?? '',
                            'gztime'   => $data['gztime'] ?? '',
                        ];
                        $results[$code] = $item;
                        // 回填缓存
                        $dsr = DataSourceResult::success(self::SOURCE_NAME, 'estimate', $item);
                        $this->setCache('estimate', $code, $dsr);
                        continue;
                    }
                }
                $results[$code] = null;
            }

            curl_multi_close($mh);
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'batch_estimate', $results, [
            'total'    => count($validCodes),
            'cached'   => count($validCodes) - count($missCodes),
            'fetched'  => count($missCodes),
        ]);
    }

    /**
     * 基金详细信息
     *
     * @param string[] $codes 基金代码数组（最多 20 个）
     */
    public function info(array $codes): DataSourceResult
    {
        $codeStr = implode(',', $codes);
        return $this->useCache('info', $codeStr, function() use ($codes) {
            $codeStr = implode(',', $codes);
            $url = "https://fundmobapi.eastmoney.com/FundMNewApi/FundMNFInfo?Fcodes={$codeStr}&pageSize=20";

            $resp = $this->http->get($url, [
                'Referer: https://fund.eastmoney.com/',
            ]);

            if ($resp['error'] || $resp['http_code'] !== 200) {
                return DataSourceResult::error(self::SOURCE_NAME, 'info', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
            }

            $parsed = HttpClient::parseJson($resp['body']);
            if (!$parsed['ok'] || !isset($parsed['data']['Datas'])) {
                return DataSourceResult::error(self::SOURCE_NAME, 'info', 'parse_error', '解析基金数据失败');
            }

            $funds = [];
            foreach ($parsed['data']['Datas'] as $item) {
                $funds[] = [
                    'code'          => $item['FCODE'] ?? '',
                    'name'          => $item['SHORTNAME'] ?? '',
                    'type'          => $item['FTYPE'] ?? '',
                    'nav_date'      => $item['PDATE'] ?? '',
                    'nav'           => $item['NAV'] ?? '',
                    'acc_nav'       => $item['ACCNAV'] ?? '',
                    'nav_chg_rate'  => $item['NAVCHGRT'] ?? '',
                    'latest_price'  => $item['GSZ'] ?? '',
                    'is_buy'        => ($item['ISBUY'] ?? '0') === '1',
                    'min_purchase'  => $item['MINSG'] ?? '',
                    'fund_company'  => $item['JJGS'] ?? '',
                    'fund_manager'  => $item['JJJL'] ?? '',
                ];
            }

            return DataSourceResult::success(self::SOURCE_NAME, 'info', $funds, [
                'total' => count($funds),
            ]);
        });
    }

    /**
     * 基金搜索
     */
    public function search(string $keyword): DataSourceResult
    {
        return $this->useCache('search', md5($keyword), function() use ($keyword) {
            $encodedKey = urlencode($keyword);
            $url = "https://fundsuggest.eastmoney.com/FundSearch/api/FundSearchAPI.ashx?m=9&key={$encodedKey}";

            $resp = $this->http->get($url, [
                'Referer: https://fund.eastmoney.com/',
            ]);

            if ($resp['error'] || $resp['http_code'] !== 200) {
                return DataSourceResult::error(self::SOURCE_NAME, 'search', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
            }

            $parsed = HttpClient::parseJson($resp['body']);
            if (!$parsed['ok'] || !isset($parsed['data']['Datas'])) {
                return DataSourceResult::error(self::SOURCE_NAME, 'search', 'parse_error', '解析搜索结果失败');
            }

            $results = [];
            foreach ($parsed['data']['Datas'] as $item) {
                $results[] = [
                    'code'         => $item['CODE'] ?? '',
                    'name'         => $item['NAME'] ?? '',
                    'pinyin'       => $item['JP'] ?? '',
                    'category'     => $item['CATEGORY'] ?? '',
                    'type'         => $item['FTYPE'] ?? '',
                    'nav'          => $item['DWJZ'] ?? '',
                    'nav_date'     => $item['FSRQ'] ?? '',
                    'min_purchase' => $item['MINSG'] ?? '',
                    'company'      => $item['JJGS'] ?? '',
                    'manager'      => $item['JJJL'] ?? '',
                    'is_buy'       => ($item['ISBUY'] ?? '0') === '1',
                ];
            }

            return DataSourceResult::success(self::SOURCE_NAME, 'search', $results, [
                'keyword' => $keyword,
                'total'   => count($results),
            ]);
        });
    }

    // ── 缓存 ──

    private function useCache(string $action, string $key, callable $fetcher): DataSourceResult
    {
        $cached = $this->getCache($action, $key);
        if ($cached !== null) {
            $cached->meta['cache'] = 'hit';
            return $cached;
        }

        $result = $fetcher();
        if ($result->hasData()) {
            $this->setCache($action, $key, $result);
            $result->meta['cache'] = 'miss';
        }
        return $result;
    }

    private function getCache(string $action, string $key): ?DataSourceResult
    {
        $file = $this->cacheFile($action, $key);
        $content = @file_get_contents($file);
        if ($content === false) return null;

        $data = json_decode($content, true);
        if (!is_array($data)) return null;

        $ttl = self::CACHE_TTL[$action] ?? 60;
        if (time() - ($data['cached_at'] ?? 0) > $ttl) {
            @unlink($file);
            return null;
        }

        if ($data['success']) {
            return DataSourceResult::success($data['source'], $data['result_action'] ?? $data['action'], $data['data'], $data['meta'] ?? []);
        }
        return null;
    }

    private function setCache(string $action, string $key, DataSourceResult $result): void
    {
        $file = $this->cacheFile($action, $key);
        $tmp = $file . '.' . getmypid() . '.tmp';
        $data = [
            'success'       => $result->success,
            'source'        => $result->source,
            'action'        => $action,
            'result_action' => $result->action,
            'data'          => $result->data,
            'meta'          => $result->meta,
            'cached_at'     => time(),
        ];
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    private function cacheFile(string $action, string $key): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . md5("fund_{$action}_{$key}") . '.json';
    }
}
