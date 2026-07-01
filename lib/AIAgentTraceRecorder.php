<?php
/**
 * AIAgentTraceRecorder — per-run trace timeline for debugging orchestration.
 */

class AIAgentTraceRecorder
{
    /** @var string */
    private $runId;

    /** @var array */
    private $options;

    /** @var array<int,array> */
    private $events = [];

    public function __construct(string $runId, array $options = [])
    {
        $this->runId = $runId;
        $this->options = $options;
    }

    public function record(string $type, array $payload = []): void
    {
        $this->events[] = [
            'ts' => date('c'),
            'run_id' => $this->runId,
            'type' => $type,
            'payload' => $this->sanitizePayload($payload),
        ];
    }

    public function events(): array
    {
        return $this->events;
    }

    public function summary(): array
    {
        $toolStarted = 0;
        $toolFinished = 0;
        $checkpoints = 0;
        $stopReason = '';
        foreach ($this->events as $event) {
            $type = $event['type'] ?? '';
            if ($type === 'tool_call_started') $toolStarted++;
            if ($type === 'tool_call_finished') $toolFinished++;
            if ($type === 'checkpoint_created') $checkpoints++;
            if (in_array($type, ['run_finished', 'run_failed'], true)) {
                $stopReason = (string)($event['payload']['stop_reason'] ?? '');
            }
        }
        return [
            'run_id' => $this->runId,
            'event_count' => count($this->events),
            'tool_call_started' => $toolStarted,
            'tool_call_finished' => $toolFinished,
            'checkpoints' => $checkpoints,
            'stop_reason' => $stopReason,
        ];
    }

    public function flush(): void
    {
        if (empty($this->options['trace_enabled'])) {
            return;
        }

        $path = (string)($this->options['trace_log_path'] ?? '');
        if ($path === '') {
            $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fuckashare_ai_agent_traces.jsonl';
        }

        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = json_encode([
            'run_id' => $this->runId,
            'summary' => $this->summary(),
            'events' => $this->events,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            if (is_string($value) && mb_strlen($value) > 1200) {
                $sanitized[$key] = mb_substr($value, 0, 1200) . '...';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeNestedArray($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    private function sanitizeNestedArray(array $value): array
    {
        $out = [];
        $i = 0;
        foreach ($value as $key => $item) {
            if ($i >= 40) {
                $out['_truncated_items'] = count($value) - 40;
                break;
            }
            if (is_string($item) && mb_strlen($item) > 800) {
                $out[$key] = mb_substr($item, 0, 800) . '...';
            } elseif (is_array($item)) {
                $out[$key] = $this->sanitizeNestedArray($item);
            } else {
                $out[$key] = $item;
            }
            $i++;
        }
        return $out;
    }
}
