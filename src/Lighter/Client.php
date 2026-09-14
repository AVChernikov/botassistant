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
        $query = array_filter(
            $query,
            static fn ($value) => $value !== null && $value !== '',
        );

        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $this->request('GET', $url);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiException('Failed to init cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $this->userAgent,
            ],
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new ApiException('cURL error: ' . $error, 0);
        }

        if (!is_string($body) || $body === '') {
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
}
