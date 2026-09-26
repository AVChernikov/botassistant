<?php

declare(strict_types=1);

/** CLI: compact → DeepSeek → save report. Usage: php scripts/indicators_analyze_cli.php */
require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\IndicatorAnalyzer;
use Lighter\IndicatorConfig;

$cfg = IndicatorConfig::load();
$marketId = 120;
$result = IndicatorAnalyzer::run($cfg->dbPath(), $marketId, 40, true);
echo json_encode([
    'ok' => $result['ok'],
    'analyze_sec' => $result['analyze_sec'],
    'compact_bytes' => $result['compact_bytes'],
    'model' => $result['model'],
    'usage' => $result['usage'],
    'saved' => $result['saved'] ?? null,
    'title' => $result['report']['title'] ?? null,
    'summary' => $result['report']['summary'] ?? null,
    'findings_n' => is_array($result['report']['findings'] ?? null) ? count($result['report']['findings']) : 0,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

exit(!empty($result['ok']) && !empty($result['saved']['ok']) ? 0 : 1);
