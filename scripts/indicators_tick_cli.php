<?php

declare(strict_types=1);

/**
 * CLI one-shot: same work as api.php?action=indicators_tick (no HTTP).
 * Append/upsert new candles × indicators (+ refresh stats). Use --replace for full wipe.
 * Usage: php scripts/indicators_tick_cli.php [--replace]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
use Lighter\IndicatorConfig;
use Lighter\IndicatorTick;

$cfg = IndicatorConfig::load();
$network = $cfg->network();
$client = $network === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
$mode = in_array('--replace', $argv, true) ? 'replace' : 'append';

$result = IndicatorTick::run($client, $cfg, null, $mode);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

exit(!empty($result['ok']) ? 0 : 1);
