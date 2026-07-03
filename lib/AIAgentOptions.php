<?php
/**
 * AIAgentOptions — normalized runtime options for the AI agent.
 */

class AIAgentOptions
{
    public static function defaults(): array
    {
        return [
            'max_tool_rounds' => 10,
            'max_tool_calls_per_round' => 8,
            'tool_timeout' => 45,
            'tool_output_char_limit' => 60000,
            'parallel_tool_calls' => true,
            'internal_exec_token' => '',
            'internal_exec_endpoint' => '',
            'expose_tool_trace' => true,
            'auto_prefetch' => false,
            'stream_after_tool_round' => true,
            'max_tool_calls_total' => 64,
            'max_deep_dive_candidates' => 10,
            'emit_agent_events' => true,
            'suppress_reasoning_content' => false,
            'agent_profile' => '',
            'trace_enabled' => false,
            'trace_log_path' => '',
            'timeout' => 300,
            'connect_timeout' => 15,
            'max_tokens' => 8192,
            'tool_decision_max_tokens' => 4096,
        ];
    }

    public static function normalize(array $options = []): array
    {
        $merged = array_merge(self::defaults(), $options);
        foreach ([
            'max_tool_rounds',
            'max_tool_calls_per_round',
            'tool_timeout',
            'tool_output_char_limit',
            'max_tool_calls_total',
            'max_deep_dive_candidates',
            'timeout',
            'connect_timeout',
            'tool_decision_max_tokens',
        ] as $key) {
            $merged[$key] = max(1, (int)$merged[$key]);
        }
        foreach ([
            'parallel_tool_calls',
            'expose_tool_trace',
            'auto_prefetch',
            'stream_after_tool_round',
            'emit_agent_events',
            'suppress_reasoning_content',
            'trace_enabled',
        ] as $key) {
            $merged[$key] = (bool)$merged[$key];
        }
        $merged['agent_profile'] = (string)$merged['agent_profile'];
        $merged['trace_log_path'] = (string)$merged['trace_log_path'];
        $merged['internal_exec_token'] = (string)($merged['internal_exec_token'] ?? '');
        $merged['internal_exec_endpoint'] = (string)($merged['internal_exec_endpoint'] ?? '');
        return $merged;
    }
}
