<?php

declare(strict_types=1);

namespace Lighter;

/**
 * History of DeepSeek requests (analyze / clarify / custom) in SQLite.
 */
final class DeepSeekQueryStore
{
    /**
     * @param array<string, mixed> $row
     * @return array{ok: bool, id?: int, error?: string}
     */
    public static function save(string $dbPath, array $row): array
    {
        return self::call($dbPath, ['op' => 'save', 'row' => $row]);
    }

    /**
     * @return array{ok: bool, rows?: list<array<string, mixed>>, error?: string}
     */
    public static function list(string $dbPath, int $limit = 50, ?string $purpose = null): array
    {
        $payload = ['op' => 'list', 'limit' => $limit];
        if ($purpose !== null && $purpose !== '') {
            $payload['purpose'] = $purpose;
        }

        return self::call($dbPath, $payload);
    }

    /**
     * @return array{ok: bool, row?: array<string, mixed>, error?: string}
     */
    public static function get(string $dbPath, int $id): array
    {
        return self::call($dbPath, ['op' => 'get', 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function call(string $dbPath, array $payload): array
    {
        $payload['db_path'] = $dbPath;
        $root = dirname(__DIR__, 2);
        $py = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        $script = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . 'deepseek_query_log.py';
        if (!is_file($py)) {
            $py = 'python';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dsqlog_');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'tempnam failed'];
        }
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $out = [];
        $code = 0;
        exec(escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        @unlink($tmp);
        $decoded = json_decode(trim(implode("\n", $out)), true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'query log failed: ' . substr(implode("\n", $out), 0, 300)];
    }
}
