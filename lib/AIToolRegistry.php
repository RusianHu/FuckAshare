<?php
/**
 * AIToolRegistry — OpenAI-compatible adapter for the financial tool catalog.
 */

require_once __DIR__ . '/AIFinanceToolCatalog.php';

class AIToolRegistry
{
    public static function chatTools(): array
    {
        $tools = [];
        foreach (self::definitions() as $definition) {
            $tools[] = AIToolSchema::chatFunction($definition);
        }
        return $tools;
    }

    public static function definitions(): array
    {
        return AIFinanceToolCatalog::definitions();
    }

    public static function has(string $name): bool
    {
        $defs = self::definitions();
        return isset($defs[$name]);
    }

    public static function descriptions(): array
    {
        $items = [];
        foreach (self::definitions() as $name => $definition) {
            $items[$name] = $definition['description'];
        }
        return $items;
    }
}
