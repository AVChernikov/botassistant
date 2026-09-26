<?php

declare(strict_types=1);

namespace Lighter;

/**
 * Persist / load AI pattern reports (SQLite via Python).
 */
final class IndicatorReportStore
{
    /**
     * @param array<string, mixed> $report
     * @return array{ok: bool, id?: int, error?: string}
     */
    public static function save(string $dbPath, array $report): array
    {
        return self::call($dbPath, [
            'op' => 'save',
            'report' => $report,
        ]);
    }

    /**
     * @return array{ok: bool, reports?: list<array<string, mixed>>, error?: string}
     */
    public static function list(string $dbPath, int $limit = 20, ?int $marketId = null): array
    {
        $payload = ['op' => 'list', 'limit' => $limit];
        if ($marketId !== null) {
            $payload['market_id'] = $marketId;
        }

        return self::call($dbPath, $payload);
    }

    /**
     * @return array{ok: bool, report?: array<string, mixed>, error?: string}
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
        $script = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . 'indicator_report.py';
        if (!is_file($py)) {
            $py = 'python';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'indrep_');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'tempnam failed'];
        }
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $out = [];
        $code = 0;
        exec(escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        @unlink($tmp);
        $decoded = json_decode(trim(implode("\n", $out)), true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'report py failed: ' . substr(implode("\n", $out), 0, 300)];
    }
}
