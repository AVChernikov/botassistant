<?php

declare(strict_types=1);

namespace Lighter;

use Lighter\Exception\ApiException;

/**
 * Public REST client for Lighter (zkLighter).
 *
 * Base URLs:
 * - mainnet: https://mainnet.zklighter.elliot.ai
 * - testnet: https://testnet.zklighter.elliot.ai
 *
 * Docs: https://apidocs.lighter.xyz/
 */
final class Client
{
    public const MAINNET = 'https://mainnet.zklighter.elliot.ai';
    public const TESTNET = 'https://testnet.zklighter.elliot.ai';

    private string $baseUrl;
    private int $timeout;
    private string $userAgent;

    public function __construct(
        string $baseUrl = self::MAINNET,
        int $timeout = 15,
        string $userAgent = 'botassistant-lighter-php/1.0',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->userAgent = $userAgent;
    }

    public static function mainnet(int $timeout = 15): self
    {
        return new self(self::MAINNET, $timeout);
    }

    public static function testnet(int $timeout = 15): self
    {
        return new self(self::TESTNET, $timeout);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** GET /api/v1/orderBooks */
    public function orderBooks(?int $marketId = null, string $filter = 'all'): array
    {
        $query = ['filter' => $filter];
        if ($marketId !== null) {
            $query['market_id'] = $marketId;
        }

        return $this->get('/api/v1/orderBooks', $query);
    }

    /** GET /api/v1/orderBookDetails */
    public function orderBookDetails(?int $marketId = null): array
    {
        $query = [];
        if ($marketId !== null) {
            $query['market_id'] = $marketId;
        }

        return $this->get('/api/v1/orderBookDetails', $query);
    }

    /** GET /api/v1/orderBookOrders */
    public function orderBookOrders(int $marketId, int $limit = 100): array
    {
        return $this->get('/api/v1/orderBookOrders', [
            'market_id' => $marketId,
            'limit' => $limit,
        ]);
    }

    /** GET /api/v1/recentTrades */
    public function recentTrades(int $marketId, int $limit = 50): array
    {
        return $this->get('/api/v1/recentTrades', [
            'market_id' => $marketId,
            'limit' => $limit,
        ]);
    }

    /** GET /api/v1/exchangeStats */
    public function exchangeStats(): array
    {
        return $this->get('/api/v1/exchangeStats');
    }

    /** GET /api/v1/exchangeMetrics */
    public function exchangeMetrics(?string $market = null, ?string $period = null): array
    {
        $query = [];
        if ($market !== null) {
            $query['market'] = $market;
        }
        if ($period !== null) {
            $query['period'] = $period;
        }

        return $this->get('/api/v1/exchangeMetrics', $query);
    }

    /** GET /api/v1/assetDetails */
    public function assetDetails(?int $assetId = null): array
    {
        $query = [];
        if ($assetId !== null) {
            $query['asset_id'] = $assetId;
        }

        return $this->get('/api/v1/assetDetails', $query);
    }

    /** GET /api/v1/funding-rates */
    public function fundingRates(): array
    {
        return $this->get('/api/v1/funding-rates');
    }

    /** GET /api/v1/fundings */
    public function fundings(int $marketId, int $countBack = 50, ?int $startTimestamp = null): array
    {
        $query = [
            'market_id' => $marketId,
            'count_back' => $countBack,
        ];
        if ($startTimestamp !== null) {
            $query['start_timestamp'] = $startTimestamp;
        }

        return $this->get('/api/v1/fundings', $query);
    }

    /**
     * GET /api/v1/candles
     *
     * Resolutions: 1m, 5m, 15m, 30m, 1h, 4h, 12h, 1d.
     * Max 500 candles per call. Timestamps are milliseconds.
     */
    public function candles(
        int $marketId,
        string $resolution,
        ?int $startTimestamp = null,
        ?int $endTimestamp = null,
        int $countBack = 100,
    ): array {
        $allowed = ['1m', '5m', '15m', '30m', '1h', '4h', '12h', '1d'];
        if (!in_array($resolution, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported candle resolution: ' . $resolution);
        }

        $endTimestamp ??= (int) floor(microtime(true) * 1000);
        $startTimestamp ??= $endTimestamp - self::resolutionMs($resolution) * max(1, $countBack);
        $countBack = max(1, min(500, $countBack));

        return $this->get('/api/v1/candles', [
            'market_id' => $marketId,
            'resolution' => $resolution,
            'start_timestamp' => $startTimestamp,
            'end_timestamp' => $endTimestamp,
            'count_back' => $countBack,
        ]);
    }

    private static function resolutionMs(string $resolution): int
    {
        return match ($resolution) {
            '1m' => 60_000,
            '5m' => 300_000,
            '15m' => 900_000,
            '30m' => 1_800_000,
            '1h' => 3_600_000,
            '4h' => 14_400_000,
            '12h' => 43_200_000,
            '1d' => 86_400_000,
            default => 3_600_000,
        };
    }

    /** GET /api/v1/account — public lookup by index or L1 address */
    public function account(?int $index = null, ?string $l1Address = null): array
    {
        $query = [];
        if ($index !== null) {
            $query['by'] = 'index';
            $query['value'] = (string) $index;
        } elseif ($l1Address !== null) {
            $query['by'] = 'l1_address';
            $query['value'] = $l1Address;
        } else {
            throw new \InvalidArgumentException('Provide either index or l1Address');
        }

        return $this->get('/api/v1/account', $query);
    }

    /** GET /api/v1/accountsByL1Address */
    public function accountsByL1Address(string $l1Address): array
    {
        return $this->get('/api/v1/accountsByL1Address', [
            'l1_address' => $l1Address,
        ]);
    }

    /**
     * Low-level GET request.
     *
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $this->buildUrl($path, $query));
    }

    /**
     * Parallel GET requests (one transport round-trip on Windows).
     *
     * @param array<string, array{0:string,1?:array<string, scalar|null>}> $requests key => [path, query]
     * @return array<string, array<string, mixed>>
     */
    public function getMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $urls = [];
        foreach ($requests as $key => [$path, $query]) {
            $urls[(string) $key] = $this->buildUrl($path, $query ?? []);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $bodies = $this->requestManyWithPowerShell($urls);
            $out = [];
            foreach ($bodies as $key => [$status, $body]) {
                $out[$key] = $this->decodeResponse($status, $body);
            }
            return $out;
        }

        $out = [];
        foreach ($urls as $key => $url) {
            $out[$key] = $this->request('GET', $url);
        }
        return $out;
    }

    /**
     * Candle request URL helpers for batching.
     *
     * @return array{0:string,1:array<string, scalar|null>}
     */
    public function candlesRequest(
        int $marketId,
        string $resolution,
        ?int $startTimestamp = null,
        ?int $endTimestamp = null,
        int $countBack = 100,
    ): array {
        $allowed = ['1m', '5m', '15m', '30m', '1h', '4h', '12h', '1d'];
        if (!in_array($resolution, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported candle resolution: ' . $resolution);
        }

        $endTimestamp ??= (int) floor(microtime(true) * 1000);
        $startTimestamp ??= $endTimestamp - self::resolutionMs($resolution) * max(1, $countBack);
        $countBack = max(1, min(500, $countBack));

        return ['/api/v1/candles', [
            'market_id' => $marketId,
            'resolution' => $resolution,
            'start_timestamp' => $startTimestamp,
            'end_timestamp' => $endTimestamp,
            'count_back' => $countBack,
        ]];
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function buildUrl(string $path, array $query = []): string
    {
        $query = array_filter(
            $query,
            static fn ($value) => $value !== null && $value !== '',
        );

        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $url): array
    {
        $lastError = null;
        foreach ($this->transports() as $transport) {
            try {
                [$status, $body] = $transport($method, $url);
                return $this->decodeResponse($status, $body);
            } catch (ApiException $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new ApiException('Lighter API request failed');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(int $status, string $body): array
    {
        if ($body === '') {
            throw new ApiException('Empty response from Lighter API', $status);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException(
                'Invalid JSON from Lighter API: ' . $e->getMessage(),
                $status,
                ['raw' => $body],
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new ApiException('Unexpected JSON payload', $status, ['raw' => $decoded]);
        }

        $code = $decoded['code'] ?? $status;
        if ($status >= 400 || (is_int($code) && $code >= 400)) {
            $message = is_string($decoded['message'] ?? null)
                ? $decoded['message']
                : 'Lighter API request failed';
            throw new ApiException($message, $status ?: (int) $code, $decoded);
        }

        return $decoded;
    }

    /**
     * @return list<callable(string,string): array{0:int,1:string}>
     */
    private function transports(): array
    {
        // On this Windows host curl/OpenSSL stall ~15KB into responses; use PowerShell only.
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                fn (string $method, string $url): array => $this->requestWithPowerShell($method, $url),
            ];
        }

        $list = [];
        if (function_exists('curl_init')) {
            $list[] = fn (string $method, string $url): array => $this->requestWithCurl($method, $url);
        }
        $list[] = fn (string $method, string $url): array => $this->requestWithStream($method, $url);

        return $list;
    }

    /**
     * @param array<string, string> $urls
     * @return array<string, array{0:int,1:string}>
     */
    private function requestManyWithPowerShell(array $urls): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lighter_');
        if ($tmp === false) {
            throw new ApiException('Failed to create temp file');
        }
        $listFile = $tmp . '.urls.json';
        $outFile = $tmp . '.out.json';
        $scriptFile = $tmp . '.ps1';
        @unlink($tmp);

        $payload = [];
        foreach ($urls as $key => $url) {
            $payload[] = ['key' => (string) $key, 'url' => $url];
        }
        file_put_contents($listFile, json_encode($payload, JSON_UNESCAPED_SLASHES));

        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Add-Type -AssemblyName System.Net.Http
$listFile = $env:LIGHTER_LIST
$out = $env:LIGHTER_OUT
$ua = $env:LIGHTER_UA
$timeout = [int]$env:LIGHTER_TIMEOUT
$items = Get-Content -Raw -Path $listFile | ConvertFrom-Json
$handler = [System.Net.Http.HttpClientHandler]::new()
$client = [System.Net.Http.HttpClient]::new($handler)
$client.Timeout = [TimeSpan]::FromSeconds($timeout)
$client.DefaultRequestHeaders.TryAddWithoutValidation('Accept', 'application/json') | Out-Null
$client.DefaultRequestHeaders.TryAddWithoutValidation('User-Agent', $ua) | Out-Null
$tasks = @{}
$urls = @{}
foreach ($item in @($items)) {
  $tasks[$item.key] = $client.GetAsync($item.url)
  $urls[$item.key] = $item.url
}
$result = [ordered]@{}
foreach ($key in @($tasks.Keys)) {
  try {
    $primary = $tasks[$key]
    if (-not $primary.IsCompleted) {
      $hedgeDelay = [System.Threading.Tasks.Task]::Delay(1200)
      [System.Threading.Tasks.Task]::WhenAny(
        [System.Threading.Tasks.Task[]]@($primary, $hedgeDelay)
      ).GetAwaiter().GetResult() | Out-Null
    }

    if ($primary.IsCompleted) {
      $resp = $primary.GetAwaiter().GetResult()
    } else {
      # Lighter occasionally stalls a request for ~17 seconds. Hedge only
      # delayed requests, then use whichever connection completes first.
      $retry = $client.GetAsync($urls[$key])
      $deadline = [System.Threading.Tasks.Task]::Delay(
        [Math]::Max(5000, [Math]::Min(12000, $timeout * 1000))
      )
      $winner = [System.Threading.Tasks.Task]::WhenAny(
        [System.Threading.Tasks.Task[]]@($primary, $retry, $deadline)
      ).GetAwaiter().GetResult()
      if ($winner -eq $deadline) {
        throw "Lighter request timed out"
      }
      $resp = $winner.GetAwaiter().GetResult()
    }

    $body = $resp.Content.ReadAsStringAsync().GetAwaiter().GetResult()
    $result[$key] = @{ status = [int]$resp.StatusCode; body = $body }
  } catch {
    $result[$key] = @{ status = 0; body = ''; error = $_.Exception.Message }
  }
}
$client.Dispose()
$json = ($result | ConvertTo-Json -Compress -Depth 6)
[System.IO.File]::WriteAllText($out, $json, [System.Text.UTF8Encoding]::new($false))
PS;
        file_put_contents($scriptFile, $script);

        $env = [
            'LIGHTER_LIST' => $listFile,
            'LIGHTER_OUT' => $outFile,
            'LIGHTER_UA' => $this->userAgent,
            'LIGHTER_TIMEOUT' => (string) max(5, $this->timeout),
        ];
        foreach ($env as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }

        $cmd = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($scriptFile);
        $statusOut = [];
        $exitCode = 0;
        exec($cmd, $statusOut, $exitCode);

        foreach (array_keys($env) as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        @unlink($scriptFile);
        @unlink($listFile);

        $raw = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        if (is_file($outFile)) {
            @unlink($outFile);
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if ($raw === '') {
            throw new ApiException('PowerShell batch HTTP error', 0);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException('Invalid batch JSON: ' . $e->getMessage(), 0, ['raw' => $raw], $e);
        }
        if (!is_array($decoded)) {
            throw new ApiException('Unexpected batch payload', 0);
        }

        $out = [];
        foreach ($urls as $key => $_url) {
            $row = $decoded[$key] ?? null;
            if (!is_array($row)) {
                throw new ApiException('Missing batch result for ' . $key, 0);
            }
            $out[$key] = [(int) ($row['status'] ?? 0), (string) ($row['body'] ?? '')];
        }

        return $out;
    }

    /**
     * @return array{0:int,1:string}
     */
    private function requestWithPowerShell(string $method, string $url): array
    {
        if ($method !== 'GET') {
            throw new ApiException('PowerShell transport supports GET only');
        }

        $results = $this->requestManyWithPowerShell(['r' => $url]);
        return $results['r'];
    }

    /**
     * @return array{0:int,1:string}
     */
    private function requestWithCurl(string $method, string $url): array
    {
        $ch = \curl_init($url);
        if ($ch === false) {
            throw new ApiException('Failed to init cURL');
        }

        $opts = [
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_TIMEOUT => $this->timeout,
            \CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4,
            \CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $this->userAgent,
            ],
        ];

        $cafile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (is_file($cafile)) {
            $opts[\CURLOPT_CAINFO] = $cafile;
        }

        \curl_setopt_array($ch, $opts);

        $body = \curl_exec($ch);
        $errno = \curl_errno($ch);
        $error = \curl_error($ch);
        $status = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($errno !== 0) {
            throw new ApiException('cURL error: ' . $error, 0);
        }

        return [$status, is_string($body) ? $body : ''];
    }

    /**
     * @return array{0:int,1:string}
     */
    private function requestWithStream(string $method, string $url): array
    {
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];
        $cafile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (is_file($cafile)) {
            $ssl['cafile'] = $cafile;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => "Accept: application/json\r\nUser-Agent: {$this->userAgent}\r\n",
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
            ],
            'ssl' => $ssl,
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $err = error_get_last();
            throw new ApiException('HTTP error: ' . ($err['message'] ?? 'request failed'), 0);
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $headerLine, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, $body];
    }
}
