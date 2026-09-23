<?php

namespace App\Core;

/**
 * Napakaliit na .env reader — walang Composer/vlucas dependency, dahil
 * ang buong deploy model ng app ay "ihulog ang folder sa htdocs" (walang
 * `composer install` sa server). Tingnan ang app/bootstrap.php.
 *
 * KAPAREHO ITO ng FormFlow/app/Core/Env.php, hanggang sa pagitan ng mga
 * linya. Sinadya: iisang hosting account ang dalawang app, at isang
 * .env na kaugalian ang mas madaling tandaan kaysa dalawang bahagyang
 * magkaiba. Kapag inayos ang isa, dapat sundan ang isa pa.
 *
 * Priority ng halaga: tunay na environment variable → .env file → default.
 * SADYANG hindi tayo gumagamit ng putenv(): ayaw nating lumabas ang mga
 * secret sa phpinfo() o sa ibang script na tumatakbo sa parehong process.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    /** Basahin ang .env nang isang beses lang kada request. */
    public static function load(string $path): void
    {
        if (self::$loaded) return;
        self::$loaded = true;

        if (!is_file($path) || !is_readable($path)) return;

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            // payagan ang "export KEY=value" na estilo
            if (strncmp($line, 'export ', 7) === 0) {
                $line = ltrim(substr($line, 7));
            }

            $eq = strpos($line, '=');
            if ($eq === false) continue;

            $key = strtoupper(trim(substr($line, 0, $eq)));
            if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) continue;

            self::$vars[$key] = self::unquote(trim(substr($line, $eq + 1)));
        }
    }

    /**
     * Tanggalin ang quote sa paligid ng halaga.
     *   "a b"  → a b   (may escape: \n \r \t \" \\)
     *   'a b'  → a b   (literal, walang escape — gaya ng shell)
     *   a b #x → a b   (inline comment kapag walang quote)
     */
    private static function unquote(string $val): string
    {
        $len = strlen($val);

        if ($len >= 2) {
            $first = $val[0];
            $last  = $val[$len - 1];

            if ($first === '"' && $last === '"') {
                return str_replace(
                    ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                    ["\n", "\r", "\t", '"', '\\'],
                    substr($val, 1, -1)
                );
            }

            if ($first === "'" && $last === "'") {
                return substr($val, 1, -1);
            }
        }

        // walang quote — putulin sa inline comment (" #")
        $hash = strpos($val, ' #');
        if ($hash !== false) $val = substr($val, 0, $hash);

        return rtrim($val);
    }

    /** Halaga ng key, o $default kung wala/blangko. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $real = getenv($key);
        if ($real !== false && $real !== '') return $real;

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string) $_ENV[$key];
        if (isset(self::$vars[$key]) && self::$vars[$key] !== '') return self::$vars[$key];

        return $default;
    }

    /** true/1/yes/on = true; lahat ng iba = false. Kung wala, $default. */
    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) return $default;
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }

    /** Naka-set ba talaga ang key (kahit anong halaga)? */
    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }
}
