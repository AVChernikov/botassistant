<?php

declare(strict_types=1);

namespace Lighter;

/**
 * MySQL bridge for sim_1m_* via Python helper (isolated from live trading).
 */
final class Sim1mStore
{
    private static function py(): array
    {
        $root = dirname(__DIR__, 2);
        $py = PythonBin::path($root);
        $script = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . 'sim_1m_store.py';
        if (!is_file($py)) {
            $py = 'python';
        }
        if (!is_file($script)) {
            throw new \RuntimeException('sim_1m_store.py missing');
        }

        return [$py, $script];
    }

    /**
     * @param list<string> $extra
     * @return array<string, mixed>
     */
    private static function run(string $dbPath, string $op, array $extra = [], ?array $payload = null): array
    {
        [$py, $script] = self::py();
        $tmp = null;
        $args = [escapeshellarg($py), escapeshellarg($script), $op, escapeshellarg($dbPath)];
        if ($payload !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'sim1m_');
            if ($tmp === false) {
                return ['ok' => false, 'error' => 'tempnam failed'];
            }
            file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE));
            $args[] = '--payload';
            $args[] = escapeshellarg($tmp);
        }
        foreach ($extra as $a) {
            $args[] = $a;
        }
        $out = [];
        $code = 0;
        $cmd = 'set PYTHONIOENCODING=utf-8&& set PYTHONUTF8=1&& ' . implode(' ', $args) . ' 2>&1';
        exec($cmd, $out, $code);
        if ($tmp !== null) {
            @unlink($tmp);
        }
        $text = trim(implode("\n", $out));
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            // Windows console may mangle UTF-8; retry with replacement then ascii-only scrape.
            $fixed = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            $decoded = json_decode($fixed, true);
        }
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'store failed code=' . $code . ' out=' . substr($text, 0, 400)];
        }

        return $decoded;
    }

    /** @param array<string, mixed> $cfg */
    public static function start(string $dbPath, array $cfg): array
    {
        return self::run($dbPath, 'start', [], $cfg);
    }

    public static function stop(string $dbPath, ?int $sessionId = null): array
    {
        $extra = [];
        if ($sessionId !== null) {
            $extra[] = '--session-id';
            $extra[] = (string) $sessionId;
        }

        return self::run($dbPath, 'stop', $extra);
    }

    public static function session(string $dbPath, ?int $sessionId = null): array
    {
        $extra = [];
        if ($sessionId !== null) {
            $extra[] = '--session-id';
            $extra[] = (string) $sessionId;
        }

        return self::run($dbPath, 'session', $extra);
    }

    /** @param array<string, mixed> $bundle */
    public static function saveTick(string $dbPath, array $bundle): array
    {
        return self::run($dbPath, 'save', [], $bundle);
    }

    public static function status(string $dbPath, ?int $sessionId = null, int $ticks = 40, int $trades = 30): array
    {
        $extra = [
            '--ticks', (string) max(5, min(200, $ticks)),
            '--trades', (string) max(5, min(200, $trades)),
        ];
        if ($sessionId !== null) {
            $extra[] = '--session-id';
            $extra[] = (string) $sessionId;
        }

        return self::run($dbPath, 'status', $extra);
    }

    public static function setMethod(string $dbPath, string $method, ?int $sessionId = null): array
    {
        $extra = ['--method', escapeshellarg($method)];
        if ($sessionId !== null) {
            $extra[] = '--session-id';
            $extra[] = (string) $sessionId;
        }

        return self::run($dbPath, 'set_method', $extra);
    }

    public static function setLead(string $dbPath, string $method, string $resolution, ?int $sessionId = null): array
    {
        $extra = [
            '--method', escapeshellarg($method),
            '--resolution', escapeshellarg($resolution),
        ];
        if ($sessionId !== null) {
            $extra[] = '--session-id';
            $extra[] = (string) $sessionId;
        }

        return self::run($dbPath, 'set_method', $extra);
    }

    /** @param array<string, mixed> $payload */
    public static function setLevels(string $dbPath, array $payload, ?int $sessionId = null): array
    {
        $extra = [];
        if ($sessionId !== null) {
            $extra[] = '--session-id';
            $extra[] = (string) $sessionId;
        }

        return self::run($dbPath, 'set_levels', $extra, $payload);
    }

    /** @param array<string, mixed> $payload */
    public static function setPosition(string $dbPath, array $payload, ?int $sessionId = null): array
    {
        $extra = [];
        if ($sessionId !== null) {
            $extra[] = '--session-id';
            $extra[] = (string) $sessionId;
        }

        return self::run($dbPath, 'set_position', $extra, $payload);
    }
}
