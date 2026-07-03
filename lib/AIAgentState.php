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

    /** @var array 结构化研究状态：tools/candidates/failures/focus，供 fa_research_state_summary 读取 */
    public $researchState = [
        'focus' => '',
        'tools' => [],
        'candidates' => [],
        'failures' => [],
    ];

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

