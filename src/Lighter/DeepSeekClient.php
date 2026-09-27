<?php

declare(strict_types=1);

namespace Lighter;

/**
 * DeepSeek chat via project Python helper (PHP CLI has no curl/OpenSSL).
 * Optionally logs every request into deepseek_queries (history).
 */
final class DeepSeekClient
{
    private string $model;
    private ?string $dbPath;
    /** @var array<string, mixed> */
    private array $logContext;

    /**
     * @param array<string, mixed> $logContext purpose, market_id, report_id, parent_query_id, meta…
     */
    public function __construct(?string $model = null, ?string $dbPath = null, array $logContext = [])
    {
        $env = self::loadEnv();
        $this->model = $model ?? self::modelFor('analyze', $env);
        if (($env['DEEPSEEK_API_KEY'] ?? '') === '') {
            throw new \RuntimeException('DEEPSEEK_API_KEY missing in mcp-lighter/.env');
        }
        $this->dbPath = $dbPath;
        $this->logContext = $logContext;
    }

    /**
     * analyze/flash → DEEPSEEK_MODEL_FLASH (pipeline).
     * loop/pro/clarify → DEEPSEEK_MODEL_PRO (2m agent).
     *
     * @param array<string, string>|null $env
     */
    public static function modelFor(string $role = 'analyze', ?array $env = null): string
    {
        $env ??= self::loadEnv();
        $role = strtolower($role);
        if (in_array($role, ['loop', 'pro', 'clarify', 'tick'], true)) {
            return $env['DEEPSEEK_MODEL_PRO']
                ?? $env['DEEPSEEK_MODEL']
                ?? 'deepseek-v4-pro';
        }

        return $env['DEEPSEEK_MODEL_FLASH']
            ?? $env['DEEPSEEK_MODEL']
            ?? 'deepseek-v4-flash';
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function withLogContext(array $ctx): self
    {
        $clone = clone $this;
        $clone->logContext = array_merge($this->logContext, $ctx);

        return $clone;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @return array{content: string, reasoning?: string|null, model: string, usage?: array<string, mixed>|null, query_id?: int|null}
     */
    public function chat(array $messages, int $maxTokens = 8000, bool $disableThinking = true): array
    {
        $root = dirname(__DIR__, 2);
        $py = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        $script = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . 'deepseek_chat.py';
        if (!is_file($py)) {
            $py = 'python';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dschat_');
        if ($tmp === false) {
            throw new \RuntimeException('tempnam failed');
        }
        $outFile = $tmp . '.out.json';
        file_put_contents($tmp, json_encode([
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'model' => $this->model,
            'disable_thinking' => $disableThinking,
        ], JSON_UNESCAPED_UNICODE));

        $t0 = microtime(true);
        $out = [];
        $code = 0;
        exec(
            escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($outFile) . ' 2>&1',
            $out,
            $code,
        );
        @unlink($tmp);
        $durationMs = (int) round((microtime(true) - $t0) * 1000);

        $userPrompt = '';
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user') {
                $userPrompt = (string) ($m['content'] ?? '');
            }
        }

        if (!is_file($outFile)) {
            $this->logFailure($messages, $userPrompt, $durationMs, 'no out file; ' . substr(implode("\n", $out), 0, 300));
            throw new \RuntimeException('DeepSeek failed: no out file; ' . substr(implode("\n", $out), 0, 300));
        }
        $text = (string) file_get_contents($outFile);
        @unlink($outFile);
        $decoded = json_decode($text, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            $err = is_array($decoded) ? (string) ($decoded['error'] ?? $text) : $text;
            $this->logFailure($messages, $userPrompt, $durationMs, $err);
            throw new \RuntimeException('DeepSeek failed: ' . substr($err, 0, 400));
        }

        $result = [
            'content' => (string) ($decoded['content'] ?? ''),
            'reasoning' => isset($decoded['reasoning']) ? (string) $decoded['reasoning'] : null,
            'model' => (string) ($decoded['model'] ?? $this->model),
            'usage' => $decoded['usage'] ?? null,
            'query_id' => null,
        ];

        if ($this->dbPath !== null && $this->dbPath !== '') {
            $saved = DeepSeekQueryStore::save($this->dbPath, [
                'purpose' => (string) ($this->logContext['purpose'] ?? 'custom'),
                'model' => $result['model'],
                'market_id' => $this->logContext['market_id'] ?? null,
                'report_id' => $this->logContext['report_id'] ?? null,
                'parent_query_id' => $this->logContext['parent_query_id'] ?? null,
                'prompt_text' => $userPrompt,
                'messages' => $messages,
                'response_text' => $result['content'],
                'reasoning_text' => $result['reasoning'],
                'usage' => $result['usage'],
                'duration_ms' => $durationMs,
                'status' => 'ok',
                'meta' => $this->logContext['meta'] ?? null,
            ]);
            if (!empty($saved['ok'])) {
                $result['query_id'] = (int) ($saved['id'] ?? 0);
            }
        }

        return $result;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    private function logFailure(array $messages, string $userPrompt, int $durationMs, string $error): void
    {
        if ($this->dbPath === null || $this->dbPath === '') {
            return;
        }
        DeepSeekQueryStore::save($this->dbPath, [
            'purpose' => (string) ($this->logContext['purpose'] ?? 'custom'),
            'model' => $this->model,
            'market_id' => $this->logContext['market_id'] ?? null,
            'report_id' => $this->logContext['report_id'] ?? null,
            'parent_query_id' => $this->logContext['parent_query_id'] ?? null,
            'prompt_text' => $userPrompt,
            'messages' => $messages,
            'response_text' => null,
            'duration_ms' => $durationMs,
            'status' => 'error',
            'error_text' => substr($error, 0, 1000),
            'meta' => $this->logContext['meta'] ?? null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function loadEnv(): array
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '.env';
        $out = [];
        if (!is_file($path)) {
            return $out;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v);
        }

        return $out;
    }
}
