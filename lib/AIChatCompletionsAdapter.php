<?php
/**
 * AIChatCompletionsAdapter — Chat Completions transport for the agent runtime.
 */

require_once __DIR__ . '/AIAgentStreamEmitter.php';

class AIChatCompletionsAdapter
{
    /** @var array */
    private $channel;

    /** @var array */
    private $options;

    /** @var callable|null */
    private $transport;

    /** @var callable|null */
    private $streamTransport;

    /** @var AIAgentStreamEmitter */
    private $stream;

    public function __construct(array $channel, array $options, AIAgentStreamEmitter $stream, ?callable $transport = null, ?callable $streamTransport = null)
    {
        $this->channel = $channel;
        $this->options = $options;
        $this->stream = $stream;
        $this->transport = $transport;
        $this->streamTransport = $streamTransport;
    }

    public function payload(array $messages, bool $stream): array
    {
        $payload = [
            'model' => (string)$this->channel['model'],
            'messages' => $messages,
            'stream' => $stream,
        ];
        $maxTokens = $stream
            ? (int)($this->options['max_tokens'] ?? 8192)
            : (int)($this->options['tool_decision_max_tokens'] ?? 4096);
        if ($maxTokens > 0) {
            if ($this->isMiMoThinkingModel()) {
                // MiMo-V2.5 的深度思考与正式回答共用此额度。
                $payload['max_completion_tokens'] = $maxTokens;
            } else {
                $payload['max_tokens'] = $maxTokens;
            }
        }
        if ($this->isMiMoThinkingModel()) {
            // Agent 工具链保持 thinking 开启；后续轮次必须完整回传 reasoning_content。
            $payload['thinking'] = ['type' => 'enabled'];
        }
        return $payload;
    }

    public function isMiMoThinkingModel(): bool
    {
        $model = strtolower(trim((string)($this->channel['model'] ?? '')));
        return in_array($model, ['mimo-v2.5', 'mimo-v2.5-pro'], true);
    }

    public function complete(array $payload): array
    {
        if ($this->transport) {
            $result = call_user_func($this->transport, $payload);
            if (!is_array($result)) {
                throw new RuntimeException('测试 transport 必须返回数组');
            }
            return $result;
        }

        $started = microtime(true);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->channel['api_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->channel['api_key'],
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(3, (int)$this->options['tool_timeout']));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$this->options['connect_timeout']);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $durationMs = (int)round((microtime(true) - $started) * 1000);

        if ($errno) {
            throw new RuntimeException("API请求失败(errno={$errno}, duration_ms={$durationMs}): {$error}", $httpCode ?: $errno);
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new RuntimeException("上游 AI 返回非 JSON 响应(HTTP {$httpCode}, duration_ms={$durationMs}): " . json_last_error_msg(), $httpCode ?: 0);
        }
        if (isset($json['error'])) {
            $message = is_array($json['error']) ? ($json['error']['message'] ?? json_encode($json['error'], JSON_UNESCAPED_UNICODE)) : (string)$json['error'];
            throw new RuntimeException("上游 AI 错误(HTTP {$httpCode}, duration_ms={$durationMs}): {$message}", $httpCode ?: 0);
        }
        return $json;
    }

    public function stream(array $payload, callable $emit): void
    {
        if ($this->streamTransport) {
            call_user_func($this->streamTransport, $payload, $emit);
            return;
        }

        $started = microtime(true);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->channel['api_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->channel['api_key'],
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$this->options['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$this->options['connect_timeout']);
        $httpCode = 0;
        $errorBody = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$httpCode) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', trim($header), $matches)) {
                $httpCode = (int)$matches[1];
            }
            return strlen($header);
        });
        $heartbeatInterval = (int)($this->options['heartbeat_interval'] ?? 0);
        $lastActivityAt = microtime(true);
        if ($heartbeatInterval > 0) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, function ($ch, $downloadTotal, $downloadNow, $uploadTotal, $uploadNow) use ($emit, $heartbeatInterval, &$lastActivityAt) {
                if ((microtime(true) - $lastActivityAt) >= $heartbeatInterval) {
                    $this->stream->heartbeat($emit, 'upstream_stream');
                    $lastActivityAt = microtime(true);
                }
                return connection_aborted() ? 1 : 0;
            });
        }
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($emit, &$lastActivityAt, &$httpCode, &$errorBody) {
            if (connection_aborted()) {
                return 0;
            }
            $lastActivityAt = microtime(true);
            if ($httpCode < 200 || $httpCode >= 300) {
                if (strlen($errorBody) < 65536) {
                    $errorBody .= substr($data, 0, 65536 - strlen($errorBody));
                }
                return strlen($data);
            }
            $emit($data);
            return strlen($data);
        });

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $finalHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($finalHttpCode > 0) {
            $httpCode = $finalHttpCode;
        }
        curl_close($ch);
        $durationMs = (int)round((microtime(true) - $started) * 1000);

        if ($result === false && $errno) {
            throw new RuntimeException("API请求失败(errno={$errno}, duration_ms={$durationMs}): {$error}", $httpCode ?: $errno);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $this->extractUpstreamErrorMessage($errorBody);
            throw new RuntimeException("上游 AI 错误(HTTP {$httpCode}, duration_ms={$durationMs}): {$message}", $httpCode);
        }
    }

    private function extractUpstreamErrorMessage(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '空错误响应';
        }
        $json = json_decode($body, true);
        if (is_array($json) && array_key_exists('error', $json)) {
            $error = $json['error'];
            if (is_array($error)) {
                return trim((string)($error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
            }
            return trim((string)$error);
        }
        return mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 500);
    }
}
