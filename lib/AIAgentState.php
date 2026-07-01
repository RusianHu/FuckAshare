<?php
/**
 * AIAgentState — per-request runtime state for the AI research agent.
 */

class AIAgentState
{
    /** @var string */
    public $runId;

    /** @var float */
    public $startedAt;

    /** @var int */
    public $round = 0;

    /** @var int */
    public $toolCalls = 0;

    /** @var array<string,bool> */
    public $seenCalls = [];

    /** @var array<string,bool> */
    public $flags = [];

    /** @var string */
    public $stopReason = '';

    public function __construct(?string $runId = null)
    {
        $this->runId = $runId ?: self::newRunId();
        $this->startedAt = microtime(true);
    }

    public static function newRunId(): string
    {
        try {
            return 'run_' . bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            return uniqid('run_', true);
        }
    }

    public function elapsedMs(): int
    {
        return (int)round((microtime(true) - $this->startedAt) * 1000);
    }
}

