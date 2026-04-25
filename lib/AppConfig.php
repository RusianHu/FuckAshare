<?php
/**
 * AppConfig — minimal local configuration loader.
 *
 * Loads repo-root config.php when present. Missing config is treated as an
 * empty config so development fallback behavior stays unchanged.
 */

class AppConfig
{
    /** @var array|null */
    private static $config = null;

    public static function all(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($path)) {
            $config = require $path;
            self::$config = is_array($config) ? $config : [];
            return self::$config;
        }

        self::$config = [];
        return self::$config;
    }

    public static function get(string $key, $default = null)
    {
        $value = self::all();
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function reset(): void
    {
        self::$config = null;
    }
}
