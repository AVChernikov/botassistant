<?php

declare(strict_types=1);

namespace Lighter;

/**
 * Persists full candle×indicator snapshots to SQLite via Python helper.
 * Each tick replaces previous rows for the given market+resolution scopes.
 */
final class IndicatorHistoryStore
{
    /**
     * @param list<array<string, mixed>> $rows candle×indicator signal history
     * @param list<array{market_id: int, resolution: string}> $replaceScopes
     * @param list<array<string, mixed>> $stats return / profit factor per method
     * @return array{ok: bool, inserted: int, deleted?: int, inserted_stats?: int, deleted_stats?: int, db_path: string, error?: string|null}
     */
    public static function saveRows(string $dbPath, array $rows, array $replaceScopes = [], array $stats = []): array
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'inserted' => 0, 'db_path' => $dbPath, 'error' => 'Cannot create data dir'];
        }

        $payload = json_encode([
            'db_path' => $dbPath,
            'rows' => $rows,
            'stats' => $stats,
            'replace_scopes' => $replaceScopes,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return ['ok' => false, 'inserted' => 0, 'db_path' => $dbPath, 'error' => 'json_encode failed'];
        }

        $root = dirname(__DIR__, 2);
        $py = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        $script = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . 'indicator_history_write.py';
        if (!is_file($py)) {
            $py = 'python';
        }
        if (!is_file($script)) {
            return ['ok' => false, 'inserted' => 0, 'db_path' => $dbPath, 'error' => 'indicator_history_write.py missing'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'indhist_');
        if ($tmp === false) {
            return ['ok' => false, 'inserted' => 0, 'db_path' => $dbPath, 'error' => 'tempnam failed'];
        }
        file_put_contents($tmp, $payload);

        $cmd = escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp);
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        @unlink($tmp);

        $text = trim(implode("\n", $out));
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'inserted' => 0,
                'db_path' => $dbPath,
                'error' => 'writer failed code=' . $code . ' out=' . substr($text, 0, 400),
            ];
        }

        return [
            'ok' => (bool) ($decoded['ok'] ?? false),
            'inserted' => (int) ($decoded['inserted'] ?? 0),
            'deleted' => (int) ($decoded['deleted'] ?? 0),
            'inserted_stats' => (int) ($decoded['inserted_stats'] ?? 0),
            'deleted_stats' => (int) ($decoded['deleted_stats'] ?? 0),
            'db_path' => (string) ($decoded['db_path'] ?? $dbPath),
            'error' => isset($decoded['error']) ? (string) $decoded['error'] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(string $dbPath, int $limit = 50, ?int $marketId = null, ?string $resolution = null): array
    {
        $root = dirname(__DIR__, 2);
        $py = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        $script = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . 'indicator_history_write.py';
        if (!is_file($py)) {
            $py = 'python';
        }
        $args = [
            escapeshellarg($py),
            escapeshellarg($script),
            '--read',
            escapeshellarg($dbPath),
            '--limit',
            (string) max(1, min(500, $limit)),
        ];
        if ($marketId !== null) {
            $args[] = '--market-id';
            $args[] = (string) $marketId;
        }
        if ($resolution !== null && $resolution !== '') {
            $args[] = '--resolution';
            $args[] = escapeshellarg($resolution);
        }
        $out = [];
        $code = 0;
        exec(implode(' ', $args) . ' 2>&1', $out, $code);
        $decoded = json_decode(trim(implode("\n", $out)), true);

        return is_array($decoded) && isset($decoded['rows']) && is_array($decoded['rows'])
            ? $decoded['rows']
            : [];
    }
}
