<?php
/**
 * AshareBridge — 现有 Python Ashare 调用封装
 *
 * Phase 2: 增加独立熔断器，每次调用经过熔断检查
 */

require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/CircuitBreaker.php';
require_once __DIR__ . '/AppConfig.php';

class AshareBridge
{
    const SOURCE_NAME = 'ashare';

    /** @var string Python 可执行文件路径 */
    private $pythonBinary;

    /** @var string get_stock_data.py 脚本路径 */
    private $scriptPath;

    /** @var CircuitBreaker 熔断器 */
    private $breaker;

    public function __construct()
    {
        $this->breaker = new CircuitBreaker('ashare');

        $this->pythonBinary = $this->resolvePythonBinary();
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
        if (!$this->breaker->allow()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'kline', 'circuit_open', 'Ashare 接口熔断中，暂停请求');
        }

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
            $this->breaker->failure('script_error: return code ' . $returnCode);
            return DataSourceResult::error(self::SOURCE_NAME, 'kline', 'script_error', 'Python脚本执行失败: ' . implode("\n", $output));
        }

        $jsonData = implode('', $output);
        $parsed = HttpClient::parseJson($jsonData);
        if (!$parsed['ok']) {
            $this->breaker->failure('parse_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'kline', 'parse_error', '解析JSON数据失败: ' . $parsed['error']);
        }

        $this->breaker->success();

        return DataSourceResult::success(self::SOURCE_NAME, 'kline', $parsed['data'], [
            'provider_status' => 200,
        ]);
    }

    private function resolvePythonBinary(): string
    {
        $configured = AppConfig::get('python.binary', '');
        $candidates = [];
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $this->commandFragment($configured);
        }

        if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
            $candidates[] = 'py -3.10';
            $candidates[] = 'python';
        } else {
            $candidates[] = '/www/server/pyporject_evn/versions/3.10.11/bin/python3';
            $candidates[] = 'python3';
            $candidates[] = 'python';
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($this->pythonCanRunAshare($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? 'python';
    }

    private function commandFragment(string $binary): string
    {
        return is_file($binary) ? escapeshellarg($binary) : $binary;
    }

    private function pythonCanRunAshare(string $binary): bool
    {
        $command = $binary . ' -c "import pandas,requests" 2>&1';
        $output = [];
        $returnCode = 0;
        @exec($command, $output, $returnCode);
        return $returnCode === 0;
    }
}
