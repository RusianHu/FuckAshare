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
            $payload['max_tokens'] = $maxTokens;
        }
        return $payload;
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

        if ($errno) {
            throw new RuntimeException("API请求失败: {$error}", $httpCode ?: $errno);
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new RuntimeException('上游 AI 返回非 JSON 响应: ' . json_last_error_msg(), $httpCode ?: 0);
        }
        if (isset($json['error'])) {
            $message = is_array($json['error']) ? ($json['error']['message'] ?? json_encode($json['error'], JSON_UNESCAPED_UNICODE)) : (string)$json['error'];
            throw new RuntimeException($message, $httpCode ?: 0);
        }
        return $json;
    }

    public function stream(array $payload, callable $emit): void
    {
        if ($this->streamTransport) {
            call_user_func($this->streamTransport, $payload, $emit);
            return;
        }

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
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($emit) {
            if (connection_aborted()) {
                return 0;
            }
            $emit($data);
            return strlen($data);
        });

        $result = curl_exec($ch);
        if ($result === false && curl_errno($ch)) {
            $message = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: curl_errno($ch);
            $this->stream->error($emit, "API请求失败: {$message}", 'proxy_error', $code);
            $emit("data: [DONE]\n\n");
        }
        curl_close($ch);
    }
}
