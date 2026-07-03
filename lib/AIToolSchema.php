<?php
/**
 * AIToolSchema — helpers for OpenAI-compatible strict function schemas.
 */

class AIToolSchema
{
    public static function tool(string $name, string $description, array $properties): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'parameters' => self::strictObject($properties),
        ];
    }

    public static function chatFunction(array $definition): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'parameters' => $definition['parameters'],
                'strict' => true,
            ],
        ];
    }

    public static function strictObject(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    public static function nullableString(string $description): array
    {
        return ['type' => ['string', 'null'], 'description' => $description];
    }

    public static function nullableInteger(string $description, ?int $min = null, ?int $max = null): array
    {
        $schema = ['type' => ['integer', 'null'], 'description' => $description];
        if ($min !== null) $schema['minimum'] = $min;
        if ($max !== null) $schema['maximum'] = $max;
        return $schema;
    }

    public static function nullableEnum(array $values, string $description): array
    {
        if (!in_array(null, $values, true)) {
            $values[] = null;
        }
        return [
            'type' => ['string', 'null'],
            'enum' => $values,
            'description' => $description,
        ];
    }

    public static function nullableBoolean(string $description): array
    {
        return ['type' => ['boolean', 'null'], 'description' => $description];
    }

    public static function nullableNumber(string $description, ?float $min = null, ?float $max = null): array
    {
        $schema = ['type' => ['number', 'null'], 'description' => $description];
        if ($min !== null) $schema['minimum'] = $min;
        if ($max !== null) $schema['maximum'] = $max;
        return $schema;
    }

    /**
     * 可空数组 schema。items 必须是已构造好的 schema 片段（对象用 strictObject）。
     */
    public static function nullableArray(array $items, string $description, int $minItems = 0, int $maxItems = 100): array
    {
        return [
            'type' => ['array', 'null'],
            'items' => $items,
            'minItems' => $minItems,
            'maxItems' => $maxItems,
            'description' => $description,
        ];
    }
}
