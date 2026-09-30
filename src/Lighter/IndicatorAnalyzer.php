<?php

declare(strict_types=1);

namespace Lighter;

/**
 * Compact snapshot → DeepSeek pattern report → optional DB save.
 * All DeepSeek calls are written to deepseek_queries history.
 */
final class IndicatorAnalyzer
{
    public const SYSTEM_PROMPT = <<<'PROMPT'
Ты — аналитик крипто-фьючерсов (Lighter LIT). По сжатому снимку индикаторов найди закономерности.
Ответь СТРОГО JSON (без markdown) вида:
{
  "title": "краткий заголовок",
  "summary": "2-4 предложения",
  "findings": [
    {"pattern": "...", "evidence": "...", "tf": "30m", "confidence": 0.0}
  ],
  "best_methods": [{"i":"roc","why":"..."}],
  "risks": ["..."],
  "actions": ["..."]
}
Ключи снимка v2:
- rank: r=return% pf=profit_factor a=accuracy n=signals ls=last_signal age=sec warm=достаточно_сигналов
- e: события сигналов [t,i,s,v,c,age]
- ohlc: последние свечи [t,o,h,l,c,v] oldest→newest
- vs: сводка объёма/диапазона по этим свечам (v_sum,v_avg,v_last,range%,ret%)
- vol: ATR/RV/BBW (+age), pos: позиция/session_pnl если есть
PROMPT;

    public const CLARIFY_PROMPT = <<<'PROMPT'
Ты — аналитик крипто-фьючерсов. Ответь на уточняющий вопрос по предыдущему отчёту и снимку индикаторов.
Ответь СТРОГО JSON:
{"answer":"...","extra_findings":[],"confidence":0.0}
PROMPT;

    /**
     * @return array{
     *   ok: bool,
     *   market_id: int,
     *   symbol: string,
     *   model: string,
     *   usage: mixed,
     *   compact_bytes: int,
     *   analyze_sec: float,
     *   query_id?: int|null,
     *   report: array<string, mixed>,
     *   saved?: array<string, mixed>
     * }
     */
    public static function run(
        string $dbPath,
        int $marketId = 120,
        int $events = 40,
        bool $save = true,
    ): array {
        $t0 = microtime(true);
        $compact = IndicatorCompact::build($dbPath, $marketId, $events);
        $compactJson = json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ds = new DeepSeekClient(DeepSeekClient::modelFor('analyze'), $dbPath, [
            'purpose' => 'analyze',
            'market_id' => $marketId,
            'meta' => ['events' => $events],
        ]);
        $reply = $ds->chat([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => "Снимок индикаторов JSON:\n" . $compactJson],
        ], 8000, true);

        $content = trim($reply['content']);
        $parsed = json_decode($content, true);
        if (!is_array($parsed) && preg_match('/\{.*\}/s', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
        if (!is_array($parsed)) {
            $parsed = [
                'title' => 'DeepSeek raw',
                'summary' => mb_substr($content, 0, 500),
                'findings' => [],
                'raw' => $content,
            ];
        }

        $sym = $compact['m'][0]['sym'] ?? 'LIT';
        $bytes = (int) ($compact['bytes'] ?? strlen($compactJson ?: ''));
        $queryId = $reply['query_id'] ?? null;
        $result = [
            'ok' => true,
            'market_id' => $marketId,
            'symbol' => $sym,
            'model' => $reply['model'],
            'usage' => $reply['usage'],
            'compact_bytes' => $bytes,
            'analyze_sec' => round(microtime(true) - $t0, 2),
            'query_id' => $queryId,
            'report' => $parsed,
        ];

        if ($save) {
            $stored = IndicatorReportStore::save($dbPath, [
                'market_id' => $marketId,
                'symbol' => $sym,
                'model' => $reply['model'],
                'title' => (string) ($parsed['title'] ?? 'pattern report'),
                'summary' => (string) ($parsed['summary'] ?? ''),
                'findings' => $parsed['findings'] ?? $parsed,
                'compact_bytes' => $bytes,
                'raw_response' => $content,
            ]);
            $result['saved'] = $stored;
            if (!empty($stored['ok']) && $queryId) {
                self::linkReport($dbPath, (int) $queryId, (int) ($stored['id'] ?? 0));
            }
        }

        return $result;
    }

    /**
     * Уточняющий запрос к DeepSeek Pro (2m loop) по сохранённому отчёту.
     *
     * @return array<string, mixed>
     */
    public static function clarify(
        string $dbPath,
        string $question,
        int $reportId,
        ?int $parentQueryId = null,
        int $marketId = 120,
    ): array {
        $rep = IndicatorReportStore::get($dbPath, $reportId);
        if (empty($rep['ok']) || empty($rep['report'])) {
            return ['ok' => false, 'error' => 'report not found'];
        }
        $report = $rep['report'];
        $compact = IndicatorCompact::build($dbPath, $marketId, 20);
        $compactJson = json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $reportJson = json_encode([
            'title' => $report['title'] ?? null,
            'summary' => $report['summary'] ?? null,
            'findings' => $report['findings'] ?? $report['findings_json'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        $ds = new DeepSeekClient(DeepSeekClient::modelFor('clarify'), $dbPath, [
            'purpose' => 'clarify',
            'market_id' => $marketId,
            'report_id' => $reportId,
            'parent_query_id' => $parentQueryId,
            'meta' => ['question' => $question],
        ]);
        $reply = $ds->chat([
            ['role' => 'system', 'content' => self::CLARIFY_PROMPT],
            ['role' => 'user', 'content' => "Отчёт:\n{$reportJson}\n\nСнимок (сжатый):\n{$compactJson}\n\nУточнение:\n{$question}"],
        ], 4000, true);

        $content = trim($reply['content']);
        $parsed = json_decode($content, true);
        if (!is_array($parsed) && preg_match('/\{.*\}/s', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }

        return [
            'ok' => true,
            'query_id' => $reply['query_id'] ?? null,
            'report_id' => $reportId,
            'model' => $reply['model'],
            'usage' => $reply['usage'],
            'answer' => is_array($parsed) ? $parsed : ['answer' => $content],
        ];
    }

    private static function linkReport(string $dbPath, int $queryId, int $reportId): void
    {
        $root = dirname(__DIR__, 2);
        $py = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        $script = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . 'deepseek_query_log.py';
        if (!is_file($py)) {
            $py = 'python';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dslink_');
        if ($tmp === false) {
            return;
        }
        file_put_contents($tmp, json_encode([
            'db_path' => $dbPath,
            'op' => 'link_report',
            'id' => $queryId,
            'report_id' => $reportId,
        ], JSON_UNESCAPED_UNICODE));
        exec(escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
    }
}
