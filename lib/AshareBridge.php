<?php
/**
 * AshareBridge — 现有 Python Ashare 调用封装
 *
 * 封装 api.php 中的 Python 脚本调用逻辑
 */

require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/HttpClient.php';

class AshareBridge
{
    const SOURCE_NAME = 'ashare';

    /** @var string Python 可执行文件路径 */
    private $pythonBinary;

    /** @var string get_stock_data.py 脚本路径 */
    private $scriptPath;

    public function __construct()
    {
        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            $this->pythonBinary = 'python';
        } else {
            $this->pythonBinary = '/www/server/pyporject_evn/versions/3.10.11/bin/python3';
        }
        $this->scriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'get_stock_data.py';
    }

    /**
     * 获取 K 线数据
     *
     * @param string $code      股票代码 (sh600519 / 600519.XSHG 等)
     * @param string $frequency 频率 (1m/5m/15m/30m/60m/1d/1w/1M)
     * @param int    $count     条数
     * @param string $endDate   结束日期 (YYYY-MM-DD)
     * @return DataSourceResult
     */
    public function kline(string $code, string $frequency = '1d', int $count = 10, string $endDate = ''): DataSourceResult
    {
        $escapedScript   = escapeshellarg($this->scriptPath);
        $escapedCode     = escapeshellarg($code);
        $escapedFreq     = escapeshellarg($frequency);
        $escapedCount    = escapeshellarg((string)$count);
        $escapedEndDate  = escapeshellarg($endDate);

        $command = "{$this->pythonBinary} {$escapedScript} {$escapedCode} {$escapedFreq} {$escapedCount} {$escapedEndDate} 2>&1";

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return DataSourceResult::error(self::SOURCE_NAME, 'kline', 'script_error', 'Python脚本执行失败: ' . implode("\n", $output));
        }

        $jsonData = implode('', $output);
        $parsed = HttpClient::parseJson($jsonData);
        if (!$parsed['ok']) {
            return DataSourceResult::error(self::SOURCE_NAME, 'kline', 'parse_error', '解析JSON数据失败: ' . $parsed['error']);
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'kline', $parsed['data'], [
            'provider_status' => 200,
        ]);
    }
}
