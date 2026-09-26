<?php

declare(strict_types=1);

namespace Lighter;

/**
 * Build a compact indicator snapshot for LLM agents (DeepSeek).
 */
final class IndicatorCompact
{
    /**
     * @return array<string, mixed>
     */
    public static function build(string $dbPath, ?int $marketId = null, int $signalEventsPerTf = 40): array
    {
        $root = dirname(__DIR__, 2);
        $py = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        $script = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . 'indicator_compact.py';
        if (!is_file($py)) {
            $py = 'python';
        }
        if (!is_file($script)) {
            throw new \RuntimeException('indicator_compact.py missing');
        }

        $args = [
            escapeshellarg($py),
            escapeshellarg($script),
            escapeshellarg($dbPath),
            '--events',
            (string) max(5, min(200, $signalEventsPerTf)),
        ];
        if ($marketId !== null) {
            $args[] = '--market-id';
            $args[] = (string) $marketId;
        }
        $out = [];
        $code = 0;
        exec(implode(' ', $args) . ' 2>&1', $out, $code);
        $text = trim(implode("\n", $out));
        $decoded = json_decode($text, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            throw new \RuntimeException('compact failed: ' . substr($text, 0, 400));
        }

        return $decoded;
    }
}
