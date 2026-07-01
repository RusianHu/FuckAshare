<?php
/**
 * AIAgentCheckpointManager — lightweight observation checkpoints per run.
 */

require_once __DIR__ . '/AIAgentState.php';
require_once __DIR__ . '/AIAgentStreamEmitter.php';
require_once __DIR__ . '/AIAgentTraceRecorder.php';

class AIAgentCheckpointManager
{
    /** @var AIAgentState */
    private $state;

    /** @var AIAgentStreamEmitter */
    private $stream;

    /** @var callable */
    private $emit;

    /** @var AIAgentTraceRecorder|null */
    private $trace;

    /** @var array<int,array> */
    private $checkpoints = [];

    public function __construct(AIAgentState $state, AIAgentStreamEmitter $stream, callable $emit, ?AIAgentTraceRecorder $trace = null)
    {
        $this->state = $state;
        $this->stream = $stream;
        $this->emit = $emit;
        $this->trace = $trace;
    }

    public function create(string $label, array $messages, array $extra = []): array
    {
        $checkpoint = [
            'checkpoint_id' => $this->newCheckpointId(),
            'run_id' => $this->state->runId,
            'label' => $label,
            'round' => $this->state->round,
            'tool_calls' => $this->state->toolCalls,
            'message_count' => count($messages),
            'tool_message_count' => $this->countRole($messages, 'tool'),
            'last_role' => $this->lastRole($messages),
            'created_at' => date('c'),
            'extra' => $extra,
        ];
        $this->checkpoints[] = $checkpoint;

        $payload = $checkpoint;
        unset($payload['extra']['raw_messages']);
        $this->stream->agentEvent($this->emit, 'checkpoint_created', $payload);
        return $checkpoint;
    }

    public function latest(): ?array
    {
        if (empty($this->checkpoints)) {
            return null;
        }
        return $this->checkpoints[count($this->checkpoints) - 1];
    }

    public function all(): array
    {
        return $this->checkpoints;
    }

    private function newCheckpointId(): string
    {
        try {
            return 'ckpt_' . bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            return uniqid('ckpt_', true);
        }
    }

    private function countRole(array $messages, string $role): int
    {
        $count = 0;
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === $role) {
                $count++;
            }
        }
        return $count;
    }

    private function lastRole(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (isset($messages[$i]['role'])) {
                return (string)$messages[$i]['role'];
            }
        }
        return '';
    }
}
