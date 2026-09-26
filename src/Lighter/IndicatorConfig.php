<?php

declare(strict_types=1);

namespace Lighter;

/**
 * Loads config/indicators.env (KEY=VALUE + @indicators list).
 */
final class IndicatorConfig
{
    public const VOLATILITY_METHODS = [
        'ATR(14) pct',
        'RV(48) log',
        'Bollinger(20,2) width',
    ];

    /** @var array<string, string> */
    private array $vars;

    /** @var list<string> */
    private array $indicators;

    private string $root;

    /**
     * @param array<string, string> $vars
     * @param list<string> $indicators
     */
    private function __construct(string $root, array $vars, array $indicators)
    {
        $this->root = $root;
        $this->vars = $vars;
        $this->indicators = $indicators;
    }

    public static function load(?string $path = null): self
    {
        $root = dirname(__DIR__, 2);
        $path ??= $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'indicators.env';
        if (!is_file($path)) {
            $example = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'indicators.env.example';
            if (!is_file($example)) {
                throw new \RuntimeException('Missing indicators.env (and .example)');
            }
            $path = $example;
        }

        $vars = [];
        $indicators = [];
        $inList = false;
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Cannot read ' . $path);
        }

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            // Marker must be the whole line (optional leading #), not prose mentioning @indicators
            if (preg_match('/^#?\s*@indicators\b/i', $trim)) {
                $inList = true;
                continue;
            }
            if (str_starts_with($trim, '#')) {
                continue;
            }
            if ($inList) {
                $indicators[] = $trim;
                continue;
            }
            if (str_contains($trim, '=')) {
                [$k, $v] = explode('=', $trim, 2);
                $vars[trim($k)] = trim($v);
            }
        }

        if ($indicators === []) {
            $indicators = array_merge(TechnicalAnalysis::METHODS, self::VOLATILITY_METHODS);
        }

        return new self($root, $vars, array_values(array_unique($indicators)));
    }

    public function root(): string
    {
        return $this->root;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->vars[$key] ?? $default;
    }

    /** @return list<int> */
    public function marketIds(): array
    {
        $raw = $this->get('MARKET_IDS', '120') ?? '120';
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return $ids !== [] ? $ids : [120];
    }

    /** @return list<string> */
    public function resolutions(): array
    {
        $raw = $this->get('RESOLUTIONS', '15m,30m,1h') ?? '15m,30m,1h';
        $allowed = ['1m', '5m', '15m', '30m', '1h', '4h', '12h', '1d'];
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (in_array($part, $allowed, true)) {
                $out[] = $part;
            }
        }

        return $out !== [] ? $out : ['30m'];
    }

    public function candleCount(): int
    {
        $n = (int) ($this->get('CANDLE_COUNT', '200') ?? '200');

        return max(50, min(500, $n > 0 ? $n : 200));
    }

    public function network(): string
    {
        return ($this->get('NETWORK', 'mainnet') ?? 'mainnet') === 'testnet' ? 'testnet' : 'mainnet';
    }

    public function cronSecret(): string
    {
        return (string) ($this->get('CRON_SECRET', '') ?? '');
    }

    public function dbPath(): string
    {
        $rel = $this->get('DB_PATH', 'data/indicator_history.sqlite') ?? 'data/indicator_history.sqlite';
        if (preg_match('#^[a-zA-Z]:[\\\\/]|^[/\\\\]#', $rel)) {
            return $rel;
        }

        return $this->root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    }

    public function endpointUrl(): string
    {
        return (string) ($this->get('ENDPOINT_URL', 'http://127.0.0.1:8080/api.php') ?? 'http://127.0.0.1:8080/api.php');
    }

    public function cronIntervalSec(): int
    {
        $n = (int) ($this->get('CRON_INTERVAL_SEC', '300') ?? '300');

        return max(30, $n > 0 ? $n : 300);
    }

    /** @return list<string> */
    public function indicators(): array
    {
        return $this->indicators;
    }

    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->indicators, true);
    }
}
